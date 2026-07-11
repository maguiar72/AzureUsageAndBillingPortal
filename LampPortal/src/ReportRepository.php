<?php

declare(strict_types=1);

/**
 * Consultas de leitura para os relatorios publicos.
 */
class ReportRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** Janela de dias como filtro reutilizavel. */
    private function windowClause(int $days): array
    {
        return ['AND usage_date >= (CURDATE() - INTERVAL :days DAY)', [':days' => $days]];
    }

    /** Executa um SELECT agregado com bind de :days e :limit como inteiros. */
    private function topQuery(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Cartoes de resumo. */
    public function summary(int $days = 30): array
    {
        [$clause, $params] = $this->windowClause($days);

        $totals = $this->db->queryOne(
            "SELECT COALESCE(SUM(cost),0) AS total_cost,
                    COUNT(DISTINCT service_name)    AS service_count,
                    COUNT(DISTINCT resource_group)  AS rg_count,
                    COUNT(DISTINCT resource_id)     AS resource_count,
                    COUNT(DISTINCT subscription_id) AS sub_count,
                    MAX(currency) AS currency
               FROM usage_records
              WHERE 1=1 {$clause}",
            $params
        ) ?? [];

        $last = $this->db->queryOne(
            "SELECT started_at, finished_at, status, trigger_source, rows_upserted, message
               FROM extraction_log
              WHERE status <> 'running'
              ORDER BY id DESC LIMIT 1"
        );

        // Custo do dia mais recente com dados (independente da janela).
        $lastDay = $this->db->queryOne(
            "SELECT usage_date, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              GROUP BY usage_date
              ORDER BY usage_date DESC
              LIMIT 1"
        );

        return [
            'window_days'    => $days,
            'total_cost'     => (float)($totals['total_cost'] ?? 0),
            'currency'       => $totals['currency'] ?? 'USD',
            'service_count'  => (int)($totals['service_count'] ?? 0),
            'rg_count'       => (int)($totals['rg_count'] ?? 0),
            'resource_count' => (int)($totals['resource_count'] ?? 0),
            'sub_count'      => (int)($totals['sub_count'] ?? 0),
            'last_day_date'  => $lastDay['usage_date'] ?? null,
            'last_day_cost'  => (float)($lastDay['cost'] ?? 0),
            'last_extraction' => $last,
        ];
    }

    /** Serie temporal de custo diario (grafico de linha). */
    public function timeseries(int $days = 30): array
    {
        [$clause, $params] = $this->windowClause($days);
        return $this->db->query(
            "SELECT usage_date, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              WHERE 1=1 {$clause}
              GROUP BY usage_date
              ORDER BY usage_date ASC",
            $params
        );
    }

    /** Custo por servico (top N). */
    public function byService(int $days = 30, int $limit = 12): array
    {
        [$clause, $params] = $this->windowClause($days);
        $params[':limit'] = $limit;
        return $this->topQuery(
            "SELECT service_name, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              WHERE 1=1 {$clause}
              GROUP BY service_name
              ORDER BY cost DESC
              LIMIT :limit",
            $params
        );
    }

    /** Custo por resource group (top N). */
    public function byResourceGroup(int $days = 30, int $limit = 15): array
    {
        [$clause, $params] = $this->windowClause($days);
        $params[':limit'] = $limit;
        return $this->topQuery(
            "SELECT resource_group, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              WHERE 1=1 {$clause}
              GROUP BY resource_group
              ORDER BY cost DESC
              LIMIT :limit",
            $params
        );
    }

    /** Custo por assinatura. */
    public function bySubscription(int $days = 30): array
    {
        [$clause, $params] = $this->windowClause($days);
        return $this->db->query(
            "SELECT u.subscription_id,
                    COALESCE(s.display_name, u.subscription_id) AS display_name,
                    ROUND(SUM(u.cost),2) AS cost
               FROM usage_records u
          LEFT JOIN subscriptions s ON s.subscription_id = u.subscription_id
              WHERE 1=1 {$clause}
              GROUP BY u.subscription_id, display_name
              ORDER BY cost DESC",
            $params
        );
    }

    /**
     * Custo por recurso (VMs e todos os itens consumidos), com filtro
     * opcional de busca por nome/grupo/tipo e paginacao simples (top N).
     */
    public function byResource(int $days = 30, int $limit = 100, string $search = ''): array
    {
        [$clause, $params] = $this->windowClause($days);

        // Um unico :q (prepares nativos nao permitem reusar o placeholder).
        $searchSql = '';
        if ($search !== '') {
            $searchSql = "AND CONCAT_WS(' ', resource_name, resource_group,
                               resource_type, service_name) LIKE :q";
        }

        $sql = "SELECT resource_name, resource_group, resource_type,
                       service_name, subscription_id,
                       ROUND(SUM(cost),2) AS cost
                  FROM usage_records
                 WHERE 1=1 {$clause} {$searchSql}
                 GROUP BY resource_name, resource_group, resource_type,
                          service_name, subscription_id
                 ORDER BY cost DESC
                 LIMIT :limit";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':days', $params[':days'], PDO::PARAM_INT);
        if ($search !== '') {
            $stmt->bindValue(':q', '%' . $search . '%', PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Custo por tipo de recurso (ex.: virtualMachines, disks, etc.). */
    public function byResourceType(int $days = 30, int $limit = 15): array
    {
        [$clause, $params] = $this->windowClause($days);
        $params[':limit'] = $limit;
        return $this->topQuery(
            "SELECT resource_type, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              WHERE 1=1 {$clause} AND resource_type <> ''
              GROUP BY resource_type
              ORDER BY cost DESC
              LIMIT :limit",
            $params
        );
    }
}
