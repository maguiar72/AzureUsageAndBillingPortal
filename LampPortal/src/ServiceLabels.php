<?php

declare(strict_types=1);

/**
 * Traduz nomes tecnicos de servicos da Azure para "areas de negocio"
 * amigaveis, de modo que um gestor entenda para onde vao os gastos.
 *
 * Ex.: "Foundry Models" -> "Inteligencia Artificial",
 *      "Microsoft Fabric" -> "Power BI / Gestao de Dados".
 *
 * O mapa e facilmente editavel. A busca e por igualdade (nome em minusculas)
 * e, se nao houver, por correspondencia parcial (contem). Sem match, mantem
 * o nome original.
 */
class ServiceLabels
{
    /** servico (minusculo) => area de negocio amigavel */
    private static array $map = [
        // --- Inteligencia Artificial ---
        'foundry models'                 => 'Inteligencia Artificial',
        'azure ai foundry'               => 'Inteligencia Artificial',
        'azure openai'                   => 'Inteligencia Artificial',
        'cognitive services'             => 'Inteligencia Artificial',
        'azure machine learning'         => 'Inteligencia Artificial',
        'azure ai'                       => 'Inteligencia Artificial',
        'bot service'                    => 'Inteligencia Artificial',

        // --- Power BI / Dados / Analytics ---
        'microsoft fabric'               => 'Power BI / Gestao de Dados',
        'power bi'                       => 'Power BI / Gestao de Dados',
        'power bi embedded'              => 'Power BI / Gestao de Dados',
        'azure synapse analytics'        => 'Power BI / Gestao de Dados',
        'azure data factory'             => 'Power BI / Gestao de Dados',
        'data lake'                      => 'Power BI / Gestao de Dados',
        'azure databricks'              => 'Power BI / Gestao de Dados',
        'stream analytics'               => 'Power BI / Gestao de Dados',

        // --- Bancos de Dados ---
        'azure sql database'             => 'Banco de Dados',
        'sql database'                   => 'Banco de Dados',
        'sql managed instance'           => 'Banco de Dados',
        'azure database for mysql'       => 'Banco de Dados',
        'azure database for postgresql'  => 'Banco de Dados',
        'azure cosmos db'                => 'Banco de Dados',
        'azure cache for redis'          => 'Banco de Dados',

        // --- Servidores / Computacao ---
        'virtual machines'               => 'Servidores (Maquinas Virtuais)',
        'virtual machines licenses'      => 'Servidores (Maquinas Virtuais)',
        'azure vmware solution'          => 'Servidores (Maquinas Virtuais)',

        // --- Aplicacoes / Conteineres ---
        'azure app service'              => 'Aplicacoes e Sites',
        'app service'                    => 'Aplicacoes e Sites',
        'functions'                      => 'Aplicacoes e Sites',
        'azure kubernetes service'       => 'Conteineres',
        'container apps'                 => 'Conteineres',
        'container instances'            => 'Conteineres',
        'container registry'             => 'Conteineres',

        // --- Armazenamento ---
        'storage'                        => 'Armazenamento',
        'azure storage'                  => 'Armazenamento',
        'azure netapp files'             => 'Armazenamento',
        'backup'                         => 'Backup e Recuperacao',
        'azure site recovery'            => 'Backup e Recuperacao',

        // --- Rede ---
        'bandwidth'                      => 'Rede e Trafego',
        'content delivery network'       => 'Rede e Trafego',
        'virtual network'                => 'Rede e Trafego',
        'azure dns'                      => 'Rede e Trafego',
        'load balancer'                  => 'Rede e Trafego',
        'application gateway'            => 'Rede e Trafego',
        'azure firewall'                 => 'Rede e Trafego',
        'vpn gateway'                    => 'Rede e Trafego',
        'azure front door'               => 'Rede e Trafego',

        // --- Seguranca ---
        'key vault'                      => 'Seguranca',
        'azure security center'          => 'Seguranca',
        'microsoft defender for cloud'   => 'Seguranca',
        'azure defender'                 => 'Seguranca',
        'sentinel'                       => 'Seguranca',

        // --- Monitoramento ---
        'log analytics'                  => 'Monitoramento',
        'azure monitor'                  => 'Monitoramento',
        'application insights'           => 'Monitoramento',

        // --- Integracao / Mensageria ---
        'service bus'                    => 'Integracao e Mensageria',
        'event hubs'                     => 'Integracao e Mensageria',
        'event grid'                     => 'Integracao e Mensageria',
        'api management'                 => 'Integracao e Mensageria',
        'logic apps'                     => 'Integracao e Mensageria',

        // --- Software de terceiros ---
        'saas'                           => 'Software de Terceiros (SaaS/Marketplace)',
        'marketplace'                    => 'Software de Terceiros (SaaS/Marketplace)',
    ];

    public static function category(string $service): string
    {
        $key = mb_strtolower(trim($service));
        if ($key === '') {
            return 'Outros';
        }
        if (isset(self::$map[$key])) {
            return self::$map[$key];
        }
        // Correspondencia parcial (ex.: "Azure App Service Environment").
        foreach (self::$map as $needle => $cat) {
            if (strpos($key, $needle) !== false) {
                return $cat;
            }
        }
        return $service; // sem match -> mantem o nome tecnico
    }
}
