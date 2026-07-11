<?php
/**
 * Lista detalhada de custo por recurso (VMs e todos os itens consumidos).
 * Parametros: ?days=30&limit=100&q=texto
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new ReportRepository($db);

$days   = param_days();
$limit  = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 100;
$limit  = max(1, min(1000, $limit));
$search = trim((string)(filter_input(INPUT_GET, 'q') ?? ''));
$search = mb_substr($search, 0, 100);

json_out([
    'days'      => $days,
    'limit'     => $limit,
    'q'         => $search,
    'resources' => $repo->byResource($days, $limit, $search),
]);
