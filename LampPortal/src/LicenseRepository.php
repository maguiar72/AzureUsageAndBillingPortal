<?php

declare(strict_types=1);

/**
 * Consultas de leitura do licenciamento Microsoft 365.
 */
class LicenseRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** Mapa skuId => nome amigavel (a partir do snapshot de SKUs). */
    public function skuMap(): array
    {
        $map = [];
        foreach ($this->db->query("SELECT sku_id, friendly_name FROM license_skus") as $r) {
            $map[(string)$r['sku_id']] = (string)$r['friendly_name'];
        }
        return $map;
    }

    /** Nota da ultima extracao referente a licencas (diagnostico na UI). */
    public function extractionNote(): array
    {
        $r = $this->db->queryOne(
            "SELECT finished_at, message FROM extraction_log
              WHERE status <> 'running' ORDER BY id DESC LIMIT 1"
        );
        $msg = (string)($r['message'] ?? '');
        $note = '';
        if (preg_match('/Licencas[^|]*/u', $msg, $m)) {
            $note = trim($m[0]);
        }
        return ['when' => $r['finished_at'] ?? null, 'note' => $note];
    }

    /** Totais gerais (todas as SKUs / tenants). */
    public function totals(): array
    {
        $t = $this->db->queryOne(
            "SELECT COALESCE(SUM(enabled),0)   AS acquired,
                    COALESCE(SUM(consumed),0)  AS consumed,
                    COUNT(*)                   AS sku_count,
                    MAX(captured_at)           AS captured_at
               FROM license_skus"
        ) ?? [];

        $users = $this->db->queryOne(
            "SELECT COUNT(DISTINCT user_principal_name) AS user_count
               FROM license_assignments"
        ) ?? [];

        $acquired = (int)($t['acquired'] ?? 0);
        $consumed = (int)($t['consumed'] ?? 0);

        return [
            'acquired'    => $acquired,
            'consumed'    => $consumed,
            'available'   => max(0, $acquired - $consumed),
            'sku_count'   => (int)($t['sku_count'] ?? 0),
            'user_count'  => (int)($users['user_count'] ?? 0),
            'captured_at' => $t['captured_at'] ?? null,
        ];
    }

    /** Uma linha por SKU (plano), com adquiridas x em uso x disponiveis. */
    public function skus(): array
    {
        $rows = $this->db->query(
            "SELECT sku_id, sku_part_number, friendly_name,
                    enabled, consumed, suspended, warning, capability_status
               FROM license_skus
              ORDER BY enabled DESC, friendly_name ASC"
        );
        foreach ($rows as &$r) {
            $r['enabled']   = (int)$r['enabled'];
            $r['consumed']  = (int)$r['consumed'];
            $r['available'] = max(0, $r['enabled'] - $r['consumed']);
            $r['usage_pct'] = $r['enabled'] > 0
                ? round($r['consumed'] * 100 / $r['enabled'], 1) : 0.0;
        }
        return $rows;
    }

    /**
     * Logins (usuarios) com uma licenca. Filtro opcional por SKU e busca.
     */
    public function users(string $skuId = '', string $search = '', int $limit = 2000): array
    {
        $where = '1=1';
        $params = [];
        if ($skuId !== '') {
            $where .= ' AND a.sku_id = :sku';
            $params[':sku'] = $skuId;
        }
        if ($search !== '') {
            // Um unico :q (prepares nativos nao permitem reusar o placeholder).
            $where .= " AND CONCAT_WS(' ', a.user_principal_name, a.display_name) LIKE :q";
            $params[':q'] = '%' . $search . '%';
        }

        $sql = "SELECT a.user_principal_name, a.display_name, a.account_enabled,
                       a.sku_part_number,
                       COALESCE(s.friendly_name, a.sku_part_number) AS friendly_name
                  FROM license_assignments a
             LEFT JOIN license_skus s
                    ON s.tenant_id = a.tenant_id AND s.sku_id = a.sku_id
                 WHERE {$where}
              ORDER BY a.user_principal_name ASC
                 LIMIT :limit";

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['account_enabled'] = (int)$r['account_enabled'];
        }
        return $rows;
    }
}
