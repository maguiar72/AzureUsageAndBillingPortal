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

            $lookback = (int)($this->config['azure']['lookback_days'] ?? 365);
            $to   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $from = $to->sub(new DateInterval('P' . max(1, $lookback) . 'D'));

            // A Cost Management Query API rejeita intervalos maiores que 1 ano.
            // Como 'to' cobre o dia inteiro (23:59:59), limitamos o inicio a
            // 363 dias atras: o span total (~364 dias) fica com folga abaixo
            // de 1 ano, mantendo ~12 meses de dados.
            $maxSpanStart = $to->sub(new DateInterval('P363D'));
            if ($from < $maxSpanStart) {
                $from = $maxSpanStart;
            }

            // Extracao resiliente por assinatura: uma falha nao derruba as
            // demais; os dados ja obtidos sao preservados.
            $defaultTenant = $this->config['azure']['tenant_id'];
            $errors = [];
            foreach ($this->config['azure']['subscriptions'] as $sub) {
                $subId  = $sub['id'];
                $tenant = $sub['tenant_id'] ?? $defaultTenant;

                try {
                    $rows = $this->azure->queryUsage($subId, $tenant, $from, $to);
                    $totalRows += $this->upsertRows($subId, $rows);
                } catch (Throwable $e) {
                    $errors[] = $subId . ': ' . $e->getMessage();
                }
            }

            // Licenciamento Microsoft 365 (opcional). Uma falha aqui NAO
            // derruba a extracao de custos - vira apenas uma nota.
            $licenseNote = $this->extractLicensesSafe();

            if ($errors) {
                // Sucesso parcial: dados bons ja gravados; reporta as falhas.
                $msg = "Concluido com {$totalRows} registros; "
                     . count($errors) . ' assinatura(s) com erro: '
                     . implode(' || ', $errors) . $licenseNote;
                $this->db->execute(
                    'UPDATE extraction_log
                        SET finished_at = NOW(), status = ?, rows_upserted = ?, message = ?
                      WHERE id = ?',
                    ['error', $totalRows, mb_substr($msg, 0, 4000), $logId]
                );
                return [
                    'ok'      => false,
                    'rows'    => $totalRows,
                    'message' => $msg,
                    'log_id'  => $logId,
                ];
            }

            $this->db->execute(
                'UPDATE extraction_log
                    SET finished_at = NOW(), status = ?, rows_upserted = ?, message = ?
                  WHERE id = ?',
                ['success', $totalRows,
                 mb_substr("Extracao concluida ({$totalRows} registros).{$licenseNote}", 0, 4000),
                 $logId]
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

    /**
     * Extrai o licenciamento M365 sem lancar excecao. Retorna uma nota
     * (string) para anexar a mensagem do log ('' se ok).
     */
    private function extractLicensesSafe(): string
    {
        if (empty($this->config['azure']['licenses_enabled'])) {
            return '';
        }
        try {
            $tenants = $this->licenseTenants();
            $totalSkus = 0;
            foreach ($tenants as $tenant) {
                $totalSkus += $this->extractLicensesForTenant($tenant);
            }
            return " Licencas: {$totalSkus} SKU(s) em " . count($tenants) . ' tenant(s).';
        } catch (Throwable $e) {
            return ' Licencas (falha): ' . $e->getMessage();
        }
    }

    /** Tenants distintos a consultar para licencas. */
    private function licenseTenants(): array
    {
        $default = $this->config['azure']['tenant_id'] ?? '';
        $tenants = [];
        if ($default !== '') {
            $tenants[$default] = true;
        }
        foreach ($this->config['azure']['subscriptions'] as $sub) {
            $t = $sub['tenant_id'] ?? $default;
            if ($t !== '') {
                $tenants[$t] = true;
            }
        }
        return array_keys($tenants);
    }

    /** Snapshot de SKUs e atribuicoes de um tenant (substitui os dados). */
    private function extractLicensesForTenant(string $tenant): int
    {
        $skus  = $this->azure->getSubscribedSkus($tenant);
        $users = $this->azure->getUsersWithLicenses($tenant);

        // Mapa skuId -> skuPartNumber (para rotular as atribuicoes).
        $skuPart = [];
        foreach ($skus as $s) {
            $skuPart[(string)($s['skuId'] ?? '')] = (string)($s['skuPartNumber'] ?? '');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM license_skus WHERE tenant_id = ?')->execute([$tenant]);
            $pdo->prepare('DELETE FROM license_assignments WHERE tenant_id = ?')->execute([$tenant]);

            $insSku = $pdo->prepare(
                'INSERT INTO license_skus
                    (tenant_id, sku_id, sku_part_number, friendly_name, enabled,
                     consumed, suspended, warning, capability_status, captured_at)
                 VALUES (:t,:sid,:part,:fname,:en,:cons,:susp,:warn,:cap,NOW())'
            );
            foreach ($skus as $s) {
                $part = (string)($s['skuPartNumber'] ?? '');
                $prepaid = $s['prepaidUnits'] ?? [];
                $insSku->execute([
                    ':t'     => $tenant,
                    ':sid'   => (string)($s['skuId'] ?? ''),
                    ':part'  => mb_substr($part, 0, 100),
                    ':fname' => mb_substr(LicenseSkus::friendly($part), 0, 150),
                    ':en'    => (int)($prepaid['enabled'] ?? 0),
                    ':cons'  => (int)($s['consumedUnits'] ?? 0),
                    ':susp'  => (int)($prepaid['suspended'] ?? 0),
                    ':warn'  => (int)($prepaid['warning'] ?? 0),
                    ':cap'   => mb_substr((string)($s['capabilityStatus'] ?? ''), 0, 50),
                ]);
            }

            $insAsg = $pdo->prepare(
                'INSERT IGNORE INTO license_assignments
                    (tenant_id, user_principal_name, display_name, sku_id,
                     sku_part_number, account_enabled, captured_at)
                 VALUES (:t,:upn,:dn,:sid,:part,:en,NOW())'
            );
            foreach ($users as $u) {
                $upn = (string)($u['userPrincipalName'] ?? '');
                if ($upn === '') {
                    continue;
                }
                foreach (($u['assignedLicenses'] ?? []) as $lic) {
                    $sid = (string)($lic['skuId'] ?? '');
                    if ($sid === '') {
                        continue;
                    }
                    $insAsg->execute([
                        ':t'    => $tenant,
                        ':upn'  => mb_substr($upn, 0, 255),
                        ':dn'   => mb_substr((string)($u['displayName'] ?? ''), 0, 255),
                        ':sid'  => $sid,
                        ':part' => mb_substr($skuPart[$sid] ?? '', 0, 100),
                        ':en'   => !empty($u['accountEnabled']) ? 1 : 0,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return count($skus);
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
