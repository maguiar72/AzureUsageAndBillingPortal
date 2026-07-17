<?php

declare(strict_types=1);

/**
 * Nomes amigaveis das FUNCIONALIDADES (service plans) que compoem um plano
 * M365. Mapa parcial dos mais comuns; sem correspondencia, mantem o nome
 * tecnico. Referencia:
 *   https://learn.microsoft.com/entra/identity/users/licensing-service-plan-reference
 */
class ServicePlans
{
    private static array $map = [
        // Exchange / e-mail
        'EXCHANGE_S_ENTERPRISE'   => 'Exchange Online (Plano 2)',
        'EXCHANGE_S_STANDARD'     => 'Exchange Online (Plano 1)',
        'EXCHANGE_S_FOUNDATION'   => 'Exchange Foundation',
        'EXCHANGE_S_ARCHIVE'      => 'Exchange Online Archiving',
        // SharePoint / OneDrive
        'SHAREPOINTENTERPRISE'    => 'SharePoint Online (Plano 2)',
        'SHAREPOINTSTANDARD'      => 'SharePoint Online (Plano 1)',
        'SHAREPOINTWAC'           => 'Office para a Web',
        // Teams / comunicacao
        'TEAMS1'                  => 'Microsoft Teams',
        'MCOSTANDARD'             => 'Skype for Business Online',
        'MCOEV'                   => 'Microsoft Teams Phone',
        'MCOMEETADV'              => 'Teams Audioconferencia',
        'TEAMS_AUDIO_CONFERENCING_SELECT_DIAL_OUT' => 'Teams Audioconf. (discagem)',
        // Office apps
        'OFFICESUBSCRIPTION'      => 'Microsoft 365 Apps (Office desktop)',
        'OFFICEMOBILE_SUBSCRIPTION' => 'Office Mobile',
        // Seguranca / identidade
        'AAD_PREMIUM'             => 'Entra ID P1',
        'AAD_PREMIUM_P2'          => 'Entra ID P2',
        'AAD_BASIC'               => 'Entra ID Basico',
        'RMS_S_ENTERPRISE'        => 'Azure Rights Management',
        'RMS_S_PREMIUM'           => 'Azure Information Protection P1',
        'RMS_S_PREMIUM2'          => 'Azure Information Protection P2',
        'INTUNE_A'                => 'Microsoft Intune',
        'INTUNE_O365'             => 'Intune para Office 365',
        'MFA_PREMIUM'             => 'Autenticacao Multifator (MFA)',
        'ADALLOM_S_STANDALONE'    => 'Defender for Cloud Apps',
        'ATP_ENTERPRISE'          => 'Defender for Office 365 (P1)',
        'THREAT_INTELLIGENCE'     => 'Defender for Office 365 (P2)',
        'ATA'                     => 'Defender for Identity',
        'WINDEFATP'               => 'Defender for Endpoint',
        'MDE_SMB'                 => 'Defender for Business',
        // Power Platform / BI
        'BI_AZURE_P2'             => 'Power BI Pro',
        'BI_AZURE_P0'             => 'Power BI (Gratuito)',
        'POWER_BI_PRO'            => 'Power BI Pro',
        'FLOW_O365_P2'            => 'Power Automate para Office 365',
        'POWERAPPS_O365_P2'       => 'Power Apps para Office 365',
        'DYN365_CDS_O365_P2'      => 'Dataverse (Office 365)',
        // Colaboracao / produtividade
        'FORMS_PLAN_E3'           => 'Microsoft Forms (Plano 2)',
        'FORMS_PLAN_E5'           => 'Microsoft Forms (Plano 3)',
        'STREAM_O365_E3'          => 'Microsoft Stream',
        'STREAM_O365_E5'          => 'Microsoft Stream',
        'SWAY'                    => 'Sway',
        'YAMMER_ENTERPRISE'       => 'Viva Engage (Yammer)',
        'PROJECTWORKMANAGEMENT'   => 'Microsoft Planner',
        'KAIZALA_O365_P3'         => 'Microsoft Kaizala',
        'WHITEBOARD_PLAN2'        => 'Microsoft Whiteboard',
        'WHITEBOARD_PLAN3'        => 'Microsoft Whiteboard',
        'VIVA_LEARNING_SEEDED'    => 'Viva Learning',
        'Deskless'                => 'Microsoft StaffHub',
        'MICROSOFT_SEARCH'        => 'Microsoft Search',
        'CDS_O365_P2'             => 'Common Data Service',
        // Compliance / analytics
        'MYANALYTICS_P2'          => 'Viva Insights',
        'EXCHANGE_ANALYTICS'      => 'Insights by MyAnalytics',
        'LOCKBOX_ENTERPRISE'      => 'Customer Lockbox',
        'INFORMATION_BARRIERS'    => 'Information Barriers',
        'PAM_ENTERPRISE'          => 'Privileged Access Management',
        'PREMIUM_ENCRYPTION'      => 'Premium Encryption',
        'RECORDS_MANAGEMENT'      => 'Records Management',
        'CONTENTEXPLORER_STANDARD'=> 'Content Explorer',
        // Project / Visio
        'PROJECT_PROFESSIONAL'    => 'Project Plan 3',
        'PROJECT_CLIENT_SUBSCRIPTION' => 'Project (desktop)',
        'VISIOONLINE'             => 'Visio na Web',
        'VISIO_CLIENT_SUBSCRIPTION'  => 'Visio (desktop)',
    ];

    public static function friendly(string $name): string
    {
        $key = trim($name);
        return self::$map[$key] ?? ($key !== '' ? $key : 'Desconhecido');
    }
}
