<?php
/**
 * Resumo de licenciamento M365 (PROTEGIDO por login Entra ID).
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';
require __DIR__ . '/_auth.php';
header('Cache-Control: no-store');

$user = require_auth_api();

$db = get_db($config);
$repo = new LicenseRepository($db);

json_out([
    'user'   => $user,
    'totals' => $repo->totals(),
    'skus'   => $repo->skus(),
]);
