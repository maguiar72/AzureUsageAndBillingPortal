<?php
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new ReportRepository($db);
json_out([
    'by_service'      => $repo->byService(param_days()),
    'by_subscription' => $repo->bySubscription(param_days()),
    'by_location'     => $repo->byLocation(param_days()),
]);
