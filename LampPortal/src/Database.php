<?php

declare(strict_types=1);

/**
 * Fina camada sobre PDO (MySQL).
 */
class Database
{
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['host'],
            (int)($dbConfig['port'] ?? 3306),
            $dbConfig['name'],
            $dbConfig['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Executa um SELECT e retorna todas as linhas. */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Executa um SELECT e retorna a primeira linha (ou null). */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Executa INSERT/UPDATE/DELETE e retorna linhas afetadas. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }
}
