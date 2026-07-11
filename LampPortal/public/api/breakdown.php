<?php
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new ReportRepository($db);
$days = param_days();
$sub  = param_sub();

json_out([
    'by_category'       => $repo->byCategory($days, $sub),
    'by_service'        => $repo->byService($days, 12, $sub),
    'by_resource_group' => $repo->byResourceGroup($days, 15, $sub),
    'by_resource_type'  => $repo->byResourceType($days, 15, $sub),
    // Assinaturas sempre TODAS (para permitir trocar/limpar a selecao).
    'by_subscription'   => $repo->bySubscription($days),
]);
