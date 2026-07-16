<?php
/**
 * Logins atribuidos a uma licenca (PROTEGIDO por login Entra ID).
 * Parametros: ?sku=<skuId>&q=texto&limit=2000
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';
require __DIR__ . '/_auth.php';
header('Cache-Control: no-store');

require_auth_api();

$db = get_db($config);
$repo = new LicenseRepository($db);

$sku    = (string)(filter_input(INPUT_GET, 'sku') ?? '');
$sku    = preg_match('/^[0-9a-fA-F-]{36}$/', $sku) ? $sku : '';
$search = trim((string)(filter_input(INPUT_GET, 'q') ?? ''));
$search = mb_substr($search, 0, 100);
$limit  = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 2000;
$limit  = max(1, min(5000, $limit));

json_out([
    'sku'   => $sku,
    'q'     => $search,
    'users' => $repo->users($sku, $search, $limit),
]);
