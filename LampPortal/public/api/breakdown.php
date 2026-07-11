<?php
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new ReportRepository($db);
$days = param_days();

json_out([
    'by_category'       => $repo->byCategory($days),
    'by_service'        => $repo->byService($days),
    'by_resource_group' => $repo->byResourceGroup($days),
    'by_resource_type'  => $repo->byResourceType($days),
    'by_subscription'   => $repo->bySubscription($days),
]);
