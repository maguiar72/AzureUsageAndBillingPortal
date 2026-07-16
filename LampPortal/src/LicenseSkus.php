<?php

declare(strict_types=1);

/**
 * Traduz o skuPartNumber do Microsoft 365 (Graph) para um nome comercial
 * amigavel (Office 365 E1/E3/E5, Microsoft 365 E3/E5, etc.).
 *
 * Referencia dos nomes de produto/SKU:
 *   https://learn.microsoft.com/entra/identity/users/licensing-service-plan-reference
 *
 * O mapa e facilmente editavel; sem correspondencia, devolve o proprio
 * skuPartNumber.
 */
class LicenseSkus
{
    /** skuPartNumber (maiusculo) => nome amigavel */
    private static array $map = [
        // Office 365
        'STANDARDPACK'                    => 'Office 365 E1',
        'STANDARDWOFFPACK'                => 'Office 365 E2',
        'ENTERPRISEPACK'                  => 'Office 365 E3',
        'ENTERPRISEPREMIUM'               => 'Office 365 E5',
        'ENTERPRISEPREMIUM_NOPSTNCONF'    => 'Office 365 E5 (sem Audioconf.)',
        'DEVELOPERPACK'                   => 'Office 365 E3 Developer',
        'EXCHANGESTANDARD'                => 'Exchange Online (Plano 1)',
        'EXCHANGEENTERPRISE'              => 'Exchange Online (Plano 2)',

        // Microsoft 365
        'SPE_E3'                          => 'Microsoft 365 E3',
        'SPE_E5'                          => 'Microsoft 365 E5',
        'SPE_F1'                          => 'Microsoft 365 F3',
        'SPB'                             => 'Microsoft 365 Business Premium',
        'O365_BUSINESS_PREMIUM'           => 'Microsoft 365 Business Standard',
        'O365_BUSINESS_ESSENTIALS'        => 'Microsoft 365 Business Basic',
        'O365_BUSINESS'                   => 'Microsoft 365 Apps for Business',
        'OFFICESUBSCRIPTION'              => 'Microsoft 365 Apps for Enterprise',

        // Entra ID / seguranca
        'AAD_PREMIUM'                     => 'Entra ID P1',
        'AAD_PREMIUM_P2'                  => 'Entra ID P2',
        'EMS'                             => 'Enterprise Mobility + Security E3',
        'EMSPREMIUM'                      => 'Enterprise Mobility + Security E5',

        // Power Platform / outros comuns
        'POWER_BI_PRO'                    => 'Power BI Pro',
        'POWER_BI_STANDARD'               => 'Power BI (Gratuito)',
        'PBI_PREMIUM_PER_USER'            => 'Power BI Premium por Usuario',
        'FLOW_FREE'                       => 'Power Automate (Gratuito)',
        'PROJECTPROFESSIONAL'             => 'Project Plan 3',
        'PROJECTPREMIUM'                  => 'Project Plan 5',
        'VISIOCLIENT'                     => 'Visio Plan 2',
        'TEAMS_EXPLORATORY'              => 'Teams Exploratory',
        'MCOMEETADV'                      => 'Microsoft Teams Audioconferencia',
        'PHONESYSTEM_VIRTUALUSER'         => 'Teams Phone (Usuario Virtual)',
        'WIN10_PRO_ENT_SUB'               => 'Windows 10/11 Enterprise E3',
    ];

    public static function friendly(string $skuPartNumber): string
    {
        $key = strtoupper(trim($skuPartNumber));
        return self::$map[$key] ?? ($skuPartNumber !== '' ? $skuPartNumber : 'Desconhecido');
    }
}
