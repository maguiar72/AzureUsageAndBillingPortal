<?php
/**
 * Retorna o estado da extracao mais recente (para o front-end fazer
 * polling apos clicar em "Atualizar").
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';
header('Cache-Control: no-store');

$db = get_db($config);

$running = $db->queryOne(
    "SELECT id, started_at, trigger_source
       FROM extraction_log
      WHERE status = 'running'
      ORDER BY id DESC LIMIT 1"
);

$last = $db->queryOne(
    "SELECT id, started_at, finished_at, status, trigger_source, rows_upserted, message
       FROM extraction_log
      WHERE status <> 'running'
      ORDER BY id DESC LIMIT 1"
);

json_out([
    'is_running' => $running !== null,
    'running'    => $running,
    'last'       => $last,
]);
