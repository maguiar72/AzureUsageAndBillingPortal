<?php
/**
 * Configuracao para execucao em container (Azure Container Apps).
 *
 * NAO contem segredos: todos os valores vem de variaveis de ambiente,
 * injetadas pelo Container App / Container Apps Job (env vars e secrets).
 *
 * O Dockerfile copia este arquivo para config/config.php.
 */

/** Le uma env var com valor padrao. */
$env = static function (string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

/** Monta a lista de subscriptions a partir das env vars.
 *  Prioridade:
 *   1) AZURE_SUBSCRIPTIONS_JSON  = '[{"id":"...","display_name":"..."}]'
 *   2) AZURE_SUBSCRIPTION_IDS    = 'id1,id2,id3'
 */
$subscriptions = [];
$json = $env('AZURE_SUBSCRIPTIONS_JSON');
if ($json) {
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $subscriptions = $decoded;
    }
}
if (!$subscriptions) {
    $ids = $env('AZURE_SUBSCRIPTION_IDS', '');
    foreach (array_filter(array_map('trim', explode(',', (string)$ids))) as $id) {
        $subscriptions[] = ['id' => $id, 'display_name' => $id];
    }
}

return [
    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int)$env('DB_PORT', 3306),
        'name'    => $env('DB_NAME', 'azure_portal'),
        'user'    => $env('DB_USER', 'azure_portal'),
        'pass'    => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
        // TLS: no Azure MySQL Flexible deixe DB_SSL=1. Se DB_SSL_CA apontar
        // para um bundle valido, o certificado do servidor e verificado.
        'ssl'     => (bool)$env('DB_SSL', '1'),
        'ssl_ca'  => $env('DB_SSL_CA', '/etc/ssl/certs/azure-mysql-ca.pem'),
    ],

    'azure' => [
        // 'managed_identity' (recomendado) ou 'client_secret'
        'auth_method'   => $env('AZURE_AUTH_METHOD', 'managed_identity'),

        'tenant_id'     => $env('AZURE_TENANT_ID', ''),
        // Para user-assigned MI, este e o client_id da identidade.
        // Para client_secret, e o app (client) id do service principal.
        'client_id'     => $env('AZURE_CLIENT_ID', ''),
        'client_secret' => $env('AZURE_CLIENT_SECRET', ''),

        'login_url'      => $env('AZURE_LOGIN_URL', 'https://login.microsoftonline.com'),
        'management_url' => $env('AZURE_MANAGEMENT_URL', 'https://management.azure.com'),
        'scope'          => $env('AZURE_SCOPE', 'https://management.azure.com/.default'),

        'subscriptions'  => $subscriptions,
        'lookback_days'  => (int)$env('AZURE_LOOKBACK_DAYS', 60),
        'api_version'    => $env('AZURE_API_VERSION', '2023-11-01'),
        'currency'       => $env('APP_CURRENCY', 'USD'),
    ],

    'app' => [
        'timezone'   => $env('APP_TIMEZONE', 'America/Sao_Paulo'),
        'site_name'  => $env('APP_SITE_NAME', 'Portal de Uso e Faturamento Azure'),
        'currency'   => $env('APP_CURRENCY', 'USD'),
        'refresh_min_interval' => (int)$env('APP_REFRESH_MIN_INTERVAL', 300),
        'runtime_dir' => $env('APP_RUNTIME_DIR', '/tmp/azure-portal-runtime'),
    ],
];
