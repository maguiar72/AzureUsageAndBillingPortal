<#
.SYNOPSIS
    Coleta o licenciamento Microsoft 365 (SKUs + atribuicoes por usuario) via
    Microsoft Graph PowerShell e envia para o Portal (api/license_import.php).

    Use quando NAO for possivel conceder permissoes de aplicativo do Graph a
    Managed Identity: aqui a coleta usa as credenciais DELEGADAS do admin que
    roda o script (Global Reader / User Administrator / Global Admin bastam).

.PREREQUISITOS
    Install-Module Microsoft.Graph -Scope CurrentUser

.EXEMPLO
    ./Export-M365Licenses.ps1 -PortalUrl "https://custos-azure.trf3.jus.br" -ImportToken "SEU_TOKEN"

.AGENDAMENTO (opcional)
    Rode diariamente pelo Agendador de Tarefas do Windows chamando:
      pwsh -File Export-M365Licenses.ps1 -PortalUrl ... -ImportToken ...
#>

param(
    [Parameter(Mandatory = $true)][string]$PortalUrl,
    [Parameter(Mandatory = $true)][string]$ImportToken
)

$ErrorActionPreference = 'Stop'

Write-Host "Conectando ao Microsoft Graph (login do admin)..." -ForegroundColor Cyan
Connect-MgGraph -Scopes "Organization.Read.All", "User.Read.All" -NoWelcome

$tenantId = (Get-MgContext).TenantId
Write-Host "Tenant: $tenantId" -ForegroundColor DarkGray

# ---- SKUs (planos: adquiridas x em uso) ----
Write-Host "Coletando SKUs (subscribedSkus)..." -ForegroundColor Cyan
$skus = Get-MgSubscribedSku -All | ForEach-Object {
    [pscustomobject]@{
        skuId            = $_.SkuId
        skuPartNumber    = $_.SkuPartNumber
        enabled          = [int]$_.PrepaidUnits.Enabled
        consumed         = [int]$_.ConsumedUnits
        suspended        = [int]$_.PrepaidUnits.Suspended
        warning          = [int]$_.PrepaidUnits.Warning
        capabilityStatus = "$($_.CapabilityStatus)"
    }
}
Write-Host ("  {0} SKU(s)." -f $skus.Count) -ForegroundColor DarkGray

# ---- Atribuicoes (logins x licencas) ----
Write-Host "Coletando usuarios e licencas atribuidas..." -ForegroundColor Cyan
$assignments = New-Object System.Collections.Generic.List[object]
Get-MgUser -All -Property "userPrincipalName,displayName,accountEnabled,assignedLicenses" |
    ForEach-Object {
        $u = $_
        foreach ($lic in $u.AssignedLicenses) {
            $assignments.Add([pscustomobject]@{
                userPrincipalName = $u.UserPrincipalName
                displayName       = $u.DisplayName
                accountEnabled    = [bool]$u.AccountEnabled
                skuId             = $lic.SkuId
            })
        }
    }
Write-Host ("  {0} atribuicao(oes)." -f $assignments.Count) -ForegroundColor DarkGray

# ---- Envia ao portal ----
$payload = @{
    tenant_id   = "$tenantId"
    skus        = $skus
    assignments = $assignments
} | ConvertTo-Json -Depth 6 -Compress

Write-Host "Enviando ao portal..." -ForegroundColor Cyan
$resp = Invoke-RestMethod -Method Post -Uri "$PortalUrl/api/license_import.php" `
    -Headers @{ "X-Import-Token" = $ImportToken } `
    -ContentType "application/json; charset=utf-8" `
    -Body ([System.Text.Encoding]::UTF8.GetBytes($payload))

Write-Host ("OK: {0}" -f $resp.message) -ForegroundColor Green
Disconnect-MgGraph | Out-Null
