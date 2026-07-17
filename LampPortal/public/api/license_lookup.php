<?php
/**
 * Consulta AO VIVO as licencas de um usuario (por login/e-mail).
 *   license_lookup.php?email=fulano@dominio
 *
 * Chama o Microsoft Graph na hora (nao depende do snapshot). Funciona
 * assim que a permissao User.Read.All estiver concedida a Managed Identity.
 * Publico.
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';
header('Cache-Control: no-store');

$email = trim((string)(filter_input(INPUT_GET, 'email') ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'message' => 'Informe um e-mail/login valido.'], 400);
}

$db = get_db($config);
$repo = new LicenseRepository($db);

// 1) Snapshot (DB) - funciona com dados importados via PowerShell, sem MI.
$dbRows = $repo->userLicenses($email);
if ($dbRows) {
    $licenses = [];
    foreach ($dbRows as $r) {
        $licenses[] = ['sku_id' => $r['sku_id'], 'friendly_name' => $r['friendly_name']];
    }
    json_out([
        'ok'     => true,
        'found'  => true,
        'source' => 'snapshot',
        'user'   => [
            'user_principal_name' => $dbRows[0]['user_principal_name'],
            'display_name'        => $dbRows[0]['display_name'],
            'account_enabled'     => (int)$dbRows[0]['account_enabled'],
            'licenses'            => $licenses,
            'license_count'       => count($licenses),
        ],
    ]);
}

// 2) Fallback: consulta AO VIVO no Graph (requer User.Read.All na MI).
$skuMap = $repo->skuMap();
$azure  = new AzureClient($config['azure']);
$tenant = $config['azure']['tenant_id'] ?? '';

try {
    $res = $azure->getUserLicenses($email, $tenant);
} catch (Throwable $e) {
    // Tipicamente 403 (User.Read.All pendente).
    json_out([
        'ok'      => false,
        'pending' => true,
        'message' => 'Consulta indisponivel: ' . $e->getMessage()
                   . ' (verifique a permissao User.Read.All na Managed Identity).',
    ]);
}

if (empty($res['found'])) {
    json_out(['ok' => true, 'found' => false, 'email' => $email]);
}

$u = $res['user'] ?? [];
$licenses = [];
foreach (($u['assignedLicenses'] ?? []) as $lic) {
    $sid = (string)($lic['skuId'] ?? '');
    if ($sid === '') {
        continue;
    }
    $licenses[] = [
        'sku_id'        => $sid,
        'friendly_name' => $skuMap[$sid] ?? ('SKU ' . substr($sid, 0, 8)),
    ];
}

json_out([
    'ok'     => true,
    'found'  => true,
    'source' => 'live',
    'user'   => [
        'user_principal_name' => (string)($u['userPrincipalName'] ?? $email),
        'display_name'        => (string)($u['displayName'] ?? ''),
        'account_enabled'     => !empty($u['accountEnabled']) ? 1 : 0,
        'licenses'            => $licenses,
        'license_count'       => count($licenses),
    ],
]);
