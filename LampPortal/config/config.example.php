<?php
/**
 * Arquivo de configuracao de exemplo.
 *
 * Copie para `config.php` e preencha com os seus valores reais:
 *     cp config/config.example.php config/config.php
 *
 * IMPORTANTE: `config.php` contem segredos e NAO deve ser versionado
 * (ja esta no .gitignore). Mantenha-o fora da raiz publica do Apache.
 */

return [

    // -----------------------------------------------------------------
    //  Banco de dados MySQL / MariaDB
    // -----------------------------------------------------------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'azure_portal',
        'user'    => 'azure_portal',
        'pass'    => 'TROQUE_ESTA_SENHA',
        'charset' => 'utf8mb4',
        // TLS. Em MySQL local deixe false; no Azure MySQL Flexible use true
        // e, opcionalmente, aponte ssl_ca para o CA (verificacao estrita).
        'ssl'     => false,
        'ssl_ca'  => null,
    ],

    // -----------------------------------------------------------------
    //  Credenciais da Azure (Service Principal / App Registration)
    //
    //  Crie um App Registration no Entra ID (Azure AD), gere um
    //  client secret e atribua a role "Cost Management Reader" (ou
    //  "Reader") ao service principal em cada subscription monitorada.
    // -----------------------------------------------------------------
    'azure' => [
        // 'client_secret' (service principal) ou 'managed_identity'
        // (recomendado quando hospedado na Azure - sem segredos).
        'auth_method'   => 'client_secret',

        'tenant_id'     => 'SEU_TENANT_ID',
        'client_id'     => 'SEU_CLIENT_ID',
        'client_secret' => 'SEU_CLIENT_SECRET',

        // Endpoints (padrao para a nuvem publica Azure).
        'login_url'    => 'https://login.microsoftonline.com',
        'management_url' => 'https://management.azure.com',
        'scope'        => 'https://management.azure.com/.default',

        // Assinaturas a extrair. O tenant_id pode ser sobrescrito por
        // assinatura; se omitido, usa o tenant_id acima.
        'subscriptions' => [
            [
                'id'           => '00000000-0000-0000-0000-000000000000',
                'display_name' => 'Minha Assinatura Azure',
                // 'tenant_id'  => '...', // opcional
            ],
        ],

        // Janela de extracao (dias para tras a partir de hoje).
        'lookback_days' => 60,

        // Versao da API Cost Management.
        'api_version' => '2023-11-01',
    ],

    // -----------------------------------------------------------------
    //  Aplicacao
    // -----------------------------------------------------------------
    'app' => [
        // Fuso horario usado para exibir datas/horas.
        'timezone'   => 'America/Sao_Paulo',
        'site_name'  => 'Portal de Uso e Faturamento Azure',
        'currency'   => 'USD',

        // Intervalo minimo (segundos) entre refreshes manuais, para
        // evitar abuso do botao publico. 300 = 5 minutos.
        'refresh_min_interval' => 300,

        // Diretorio gravavel para o lock file da extracao.
        // Deve existir e ser gravavel pelo usuario do Apache/CLI.
        'runtime_dir' => __DIR__ . '/../runtime',
    ],
];
