<?php
/**
 * Bootstrap comum aos endpoints JSON publicos.
 */

declare(strict_types=1);

$config = require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Relatorios sao publicos: cache leve para aliviar o servidor.
header('Cache-Control: public, max-age=60');

/** Envia resposta JSON e encerra. */
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Le o parametro "days" da querystring, validado. */
function param_days(int $default = 30): int
{
    $days = filter_input(INPUT_GET, 'days', FILTER_VALIDATE_INT) ?: $default;
    return max(1, min(365, $days));
}

/** Instancia o banco, tratando erro de conexao de forma amigavel. */
function get_db(array $config): Database
{
    try {
        return new Database($config['db']);
    } catch (Throwable $e) {
        json_out(['error' => 'Falha ao conectar ao banco de dados.'], 500);
    }
}

// Disponibiliza a configuracao para quem incluir este arquivo:
//   $config = require __DIR__ . '/_common.php';
return $config;
