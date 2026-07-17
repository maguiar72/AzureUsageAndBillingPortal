<?php
/**
 * Resumo de licenciamento M365 (publico).
 * As contagens por plano exigem Organization.Read.All na Managed Identity;
 * o detalhamento por usuario exige User.Read.All. Enquanto pendentes,
 * retorna vazio + o status da ultima coleta para a UI orientar.
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new LicenseRepository($db);

$totals = $repo->totals();

json_out([
    'totals'    => $totals,
    'skus'      => $repo->skus(),
    'has_skus'  => $totals['sku_count'] > 0,
    'has_users' => $totals['user_count'] > 0,
    'status'    => $repo->extractionNote(),
]);
