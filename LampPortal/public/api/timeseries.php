<?php
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new ReportRepository($db);
json_out(['days' => param_days(), 'series' => $repo->timeseries(param_days())]);
