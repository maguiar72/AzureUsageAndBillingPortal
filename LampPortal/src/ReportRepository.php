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

    /** Numero de dias-padrao usado como janela de relatorio. */
    private function windowClause(int $days): array
    {
        return ['AND usage_date >= (CURDATE() - INTERVAL :days DAY)', [':days' => $days]];
    }

    /** Cartoes de resumo: custo total, servicos, assinaturas, ultima extracao. */
    public function summary(int $days = 30): array
    {
        [$clause, $params] = $this->windowClause($days);

        $totals = $this->db->queryOne(
            "SELECT COALESCE(SUM(cost),0) AS total_cost,
                    COUNT(DISTINCT service_name) AS service_count,
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

        return [
            'window_days'   => $days,
            'total_cost'    => (float)($totals['total_cost'] ?? 0),
            'currency'      => $totals['currency'] ?? 'USD',
            'service_count' => (int)($totals['service_count'] ?? 0),
            'sub_count'     => (int)($totals['sub_count'] ?? 0),
            'last_extraction' => $last,
        ];
    }

    /** Serie temporal de custo diario (para grafico de linha). */
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

    /** Custo por servico (top N) - grafico de rosca/barras. */
    public function byService(int $days = 30, int $limit = 10): array
    {
        [$clause, $params] = $this->windowClause($days);
        $params[':limit'] = $limit;
        $stmt = $this->db->pdo()->prepare(
            "SELECT service_name, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              WHERE 1=1 {$clause}
              GROUP BY service_name
              ORDER BY cost DESC
              LIMIT :limit"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
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

    /** Custo por regiao (top N). */
    public function byLocation(int $days = 30, int $limit = 10): array
    {
        [$clause, $params] = $this->windowClause($days);
        $params[':limit'] = $limit;
        $stmt = $this->db->pdo()->prepare(
            "SELECT resource_location, ROUND(SUM(cost),2) AS cost
               FROM usage_records
              WHERE 1=1 {$clause}
              GROUP BY resource_location
              ORDER BY cost DESC
              LIMIT :limit"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
