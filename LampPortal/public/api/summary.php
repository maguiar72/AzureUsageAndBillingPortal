<?php
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';

$db = get_db($config);
$repo = new ReportRepository($db);
json_out($repo->summary(param_days(), param_sub()));
