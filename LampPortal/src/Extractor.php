<?php

declare(strict_types=1);

/**
 * Orquestra a extracao dos dados da Azure e a gravacao no MySQL.
 *
 * Equivalente aos WebJobs (WebJobUsageDaily + WebJobBillingData) do
 * projeto original, condensado num unico processo PHP idempotente.
 *
 * Usa um lock de arquivo (flock) para garantir que apenas uma extracao
 * rode por vez - seja disparada pelo cron ou pelo botao "Atualizar".
 */
class Extractor
{
    private array $config;
    private Database $db;
    private AzureClient $azure;

    public function __construct(array $config, Database $db)
    {
        $this->config = $config;
        $this->db = $db;
        $this->azure = new AzureClient($config['azure']);
    }

    private function lockFile(): string
    {
        return rtrim($this->config['app']['runtime_dir'], '/') . '/extraction.lock';
    }

    /**
     * Executa a extracao completa.
     *
     * @param string $source 'cron' ou 'manual'
     * @return array{ok:bool, rows:int, message:string, log_id:int}
     */
    public function run(string $source = 'cron'): array
    {
        $lock = fopen($this->lockFile(), 'c');
        if ($lock === false) {
            throw new RuntimeException('Nao foi possivel abrir o lock de extracao.');
        }

        // Lock nao-bloqueante: se outra extracao ja roda, sai educadamente.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return [
                'ok'      => false,
                'rows'    => 0,
                'message' => 'Ja existe uma extracao em andamento. Tente novamente em instantes.',
                'log_id'  => 0,
            ];
        }

        $logId = (int)$this->db->execute(
            'INSERT INTO extraction_log (started_at, status, trigger_source) VALUES (NOW(), ?, ?)',
            ['running', $source]
        );
        $logId = $this->db->lastInsertId();

        $totalRows = 0;
        try {
            $this->syncSubscriptionsFromConfig();

            $lookback = (int)($this->config['azure']['lookback_days'] ?? 60);
            $to   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $from = $to->sub(new DateInterval('P' . max(1, $lookback) . 'D'));

            $defaultTenant = $this->config['azure']['tenant_id'];
            foreach ($this->config['azure']['subscriptions'] as $sub) {
                $subId  = $sub['id'];
                $tenant = $sub['tenant_id'] ?? $defaultTenant;

                $rows = $this->azure->queryUsage($subId, $tenant, $from, $to);
                $totalRows += $this->upsertRows($subId, $rows);
            }

            $this->db->execute(
                'UPDATE extraction_log
                    SET finished_at = NOW(), status = ?, rows_upserted = ?, message = ?
                  WHERE id = ?',
                ['success', $totalRows, "Extracao concluida ({$totalRows} registros).", $logId]
            );

            return [
                'ok'      => true,
                'rows'    => $totalRows,
                'message' => "Extracao concluida: {$totalRows} registros atualizados.",
                'log_id'  => $logId,
            ];
        } catch (Throwable $e) {
            $this->db->execute(
                'UPDATE extraction_log
                    SET finished_at = NOW(), status = ?, rows_upserted = ?, message = ?
                  WHERE id = ?',
                ['error', $totalRows, $e->getMessage(), $logId]
            );

            return [
                'ok'      => false,
                'rows'    => $totalRows,
                'message' => 'Erro na extracao: ' . $e->getMessage(),
                'log_id'  => $logId,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Garante que as subscriptions do config existam na tabela. */
    private function syncSubscriptionsFromConfig(): void
    {
        $defaultTenant = $this->config['azure']['tenant_id'];
        foreach ($this->config['azure']['subscriptions'] as $sub) {
            $tenant = $sub['tenant_id'] ?? $defaultTenant;

            // Nome amigavel: usa o do config; senao tenta o ARM; senao o id.
            $displayName = $sub['display_name'] ?? '';
            if ($displayName === '' || $displayName === $sub['id']) {
                $armName = $this->azure->getSubscriptionName($sub['id'], $tenant);
                $displayName = $armName !== '' ? $armName : $sub['id'];
            }

            $this->db->execute(
                'INSERT INTO subscriptions (subscription_id, display_name, tenant_id, is_active)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                                         tenant_id    = VALUES(tenant_id),
                                         is_active    = 1',
                [$sub['id'], $displayName, $tenant]
            );
        }
    }

    /** Insere/atualiza os registros de custo por recurso/dia (idempotente). */
    private function upsertRows(string $subscriptionId, array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $sql = 'INSERT INTO usage_records
                    (record_hash, subscription_id, usage_date, resource_id,
                     resource_name, resource_group, resource_type, service_name,
                     cost, currency)
                VALUES (:hash, :sub, :date, :rid, :rname, :rgroup, :rtype,
                        :service, :cost, :currency)
                ON DUPLICATE KEY UPDATE
                    cost           = VALUES(cost),
                    currency       = VALUES(currency),
                    resource_name  = VALUES(resource_name),
                    resource_group = VALUES(resource_group),
                    resource_type  = VALUES(resource_type),
                    service_name   = VALUES(service_name)';

        $stmt = $this->db->pdo()->prepare($sql);
        $count = 0;
        foreach ($rows as $r) {
            // Chave natural -> hash (evita indice unico gigante do ResourceId).
            $hash = hash('sha256', implode('|', [
                $subscriptionId,
                $r['usage_date'],
                $r['resource_id'],
                $r['service_name'],
            ]));

            $stmt->execute([
                ':hash'    => $hash,
                ':sub'     => $subscriptionId,
                ':date'    => $r['usage_date'],
                ':rid'     => mb_substr($r['resource_id'], 0, 600),
                ':rname'   => mb_substr($r['resource_name'], 0, 255),
                ':rgroup'  => mb_substr($r['resource_group'], 0, 255),
                ':rtype'   => mb_substr($r['resource_type'], 0, 255),
                ':service' => mb_substr($r['service_name'], 0, 255),
                ':cost'    => $r['cost'],
                ':currency' => $r['currency'],
            ]);
            $count++;
        }
        return $count;
    }
}
