<?php
/**
 * Bootstrap comum: carrega a configuracao, define o autoload simples
 * das classes de src/ e configura fuso horario e error handling.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// ---- Carrega configuracao --------------------------------------------
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    fwrite(STDERR, "Config nao encontrada. Copie config/config.example.php para config/config.php\n");
    die("Configuracao ausente: crie config/config.php a partir de config/config.example.php\n");
}
$config = require $configFile;

// ---- Autoload das classes de src/ (PSR-0 simplificado) ---------------
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- Fuso horario ----------------------------------------------------
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// ---- Diretorio de runtime (locks, etc.) ------------------------------
$runtimeDir = $config['app']['runtime_dir'] ?? (APP_ROOT . '/runtime');
if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0775, true);
}

return $config;
