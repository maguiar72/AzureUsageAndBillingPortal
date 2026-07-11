<?php
/**
 * Endpoint do botao "Atualizar" (refresh).
 *
 * Dispara uma nova extracao sob demanda. Para nao bloquear a resposta
 * HTTP, tenta rodar o CLI em segundo plano; se a funcao exec() estiver
 * desabilitada, faz um fallback sincrono.
 *
 * Protecoes:
 *   - aceita apenas POST;
 *   - respeita um intervalo minimo entre refreshes (config);
 *   - o Extractor usa lock de arquivo (uma extracao por vez).
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Metodo nao permitido. Use POST.'], 405);
}

$db = get_db($config);

// --- Rate limit: intervalo minimo entre refreshes ---------------------
// O tempo decorrido e calculado no proprio banco (TIMESTAMPDIFF/NOW) para
// evitar qualquer descompasso entre o fuso do PHP e o do MySQL.
$minInterval = (int)($config['app']['refresh_min_interval'] ?? 300);
$recent = $db->queryOne(
    "SELECT status, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds
       FROM extraction_log
      ORDER BY id DESC LIMIT 1"
);

if ($recent) {
    if ($recent['status'] === 'running') {
        json_out([
            'ok'      => false,
            'running' => true,
            'message' => 'Uma atualizacao ja esta em andamento.',
        ], 202);
    }
    $elapsed = (int)$recent['elapsed_seconds'];
    if ($elapsed < $minInterval) {
        $wait = $minInterval - $elapsed;
        json_out([
            'ok'      => false,
            'message' => "Aguarde {$wait}s antes de atualizar novamente.",
            'retry_after' => $wait,
        ], 429);
    }
}

// --- Tenta disparar em segundo plano ----------------------------------
$php  = PHP_BINARY ?: 'php';
$script = realpath(__DIR__ . '/../../bin/extract.php');
$logDir = rtrim($config['app']['runtime_dir'], '/');
$bgLog = $logDir . '/refresh.out.log';

$launchedBackground = false;
if (function_exists('exec') && $script) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' manual '
         . '> ' . escapeshellarg($bgLog) . ' 2>&1 &';
    // Suprime saida; retorna imediatamente.
    @exec($cmd, $out, $code);
    $launchedBackground = ($code === 0);
}

if ($launchedBackground) {
    json_out([
        'ok'      => true,
        'started' => true,
        'message' => 'Atualizacao iniciada. Os dados serao recarregados em instantes.',
    ]);
}

// --- Fallback sincrono ------------------------------------------------
try {
    $extractor = new Extractor($config, $db);
    $result = $extractor->run('manual');
    json_out([
        'ok'      => $result['ok'],
        'started' => false,
        'rows'    => $result['rows'],
        'message' => $result['message'],
    ], $result['ok'] ? 200 : 500);
} catch (Throwable $e) {
    json_out(['ok' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()], 500);
}
