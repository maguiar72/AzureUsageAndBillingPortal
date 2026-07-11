<?php
/**
 * Ponto de entrada CLI para a extracao de dados da Azure.
 *
 * Executado pelo cron a cada 12 horas (ver crontab.example) e tambem
 * reutilizado pelo endpoint web de refresh.
 *
 * Uso:
 *     php bin/extract.php            # origem = cron
 *     php bin/extract.php manual     # origem = manual
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script so pode ser executado via linha de comando.\n");
}

$config = require __DIR__ . '/../src/bootstrap.php';

$source = ($argv[1] ?? 'cron') === 'manual' ? 'manual' : 'cron';

try {
    $db = new Database($config['db']);
    $extractor = new Extractor($config, $db);
    $result = $extractor->run($source);

    $prefix = $result['ok'] ? '[OK]' : '[ERRO]';
    fwrite($result['ok'] ? STDOUT : STDERR,
        sprintf("%s %s (%s)\n", $prefix, $result['message'], date('c')));

    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . "\n");
    exit(2);
}
