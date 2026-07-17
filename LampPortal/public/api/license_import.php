<?php
/**
 * Importa um snapshot de licenciamento (SKUs + atribuicoes) enviado por um
 * script PowerShell rodado por um admin. Permite alimentar a aba SEM depender
 * de permissoes do Graph na Managed Identity.
 *
 * POST /api/license_import.php
 *   Header: X-Import-Token: <token>   (ou ?token=)
 *   Body (JSON):
 *     {
 *       "tenant_id": "....",
 *       "skus": [ { "skuId","skuPartNumber","enabled","consumed",
 *                    "suspended","warning","capabilityStatus" }, ... ],
 *       "assignments": [ { "userPrincipalName","displayName",
 *                          "accountEnabled","skuId" }, ... ]
 *     }
 *
 * Substitui (snapshot) os dados do tenant informado.
 */
declare(strict_types=1);
$config = require __DIR__ . '/_common.php';
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Use POST.'], 405);
}

$expected = (string)($config['app']['license_import_token'] ?? '');
if ($expected === '') {
    json_out(['ok' => false, 'message' => 'Importacao desabilitada (defina LICENSE_IMPORT_TOKEN).'], 503);
}

$provided = (string)($_SERVER['HTTP_X_IMPORT_TOKEN'] ?? (filter_input(INPUT_GET, 'token') ?? ''));
if (!hash_equals($expected, $provided)) {
    json_out(['ok' => false, 'message' => 'Token invalido.'], 401);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_out(['ok' => false, 'message' => 'JSON invalido.'], 400);
}

$tenant = trim((string)($data['tenant_id'] ?? ''));
if ($tenant === '') {
    json_out(['ok' => false, 'message' => 'tenant_id obrigatorio.'], 400);
}
$skus        = is_array($data['skus'] ?? null) ? $data['skus'] : [];
$assignments = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];

$db  = get_db($config);
$pdo = $db->pdo();

// Mapa skuId -> skuPartNumber (para rotular as atribuicoes).
$skuPart = [];
foreach ($skus as $s) {
    $skuPart[(string)($s['skuId'] ?? '')] = (string)($s['skuPartNumber'] ?? '');
}

try {
    $pdo->beginTransaction();

    $pdo->prepare('DELETE FROM license_skus WHERE tenant_id = ?')->execute([$tenant]);
    $pdo->prepare('DELETE FROM license_assignments WHERE tenant_id = ?')->execute([$tenant]);

    $insSku = $pdo->prepare(
        'INSERT INTO license_skus
            (tenant_id, sku_id, sku_part_number, friendly_name, enabled,
             consumed, suspended, warning, capability_status, captured_at)
         VALUES (:t,:sid,:part,:fname,:en,:cons,:susp,:warn,:cap,NOW())'
    );
    $nSku = 0;
    foreach ($skus as $s) {
        $part = (string)($s['skuPartNumber'] ?? '');
        $insSku->execute([
            ':t'     => $tenant,
            ':sid'   => (string)($s['skuId'] ?? ''),
            ':part'  => mb_substr($part, 0, 100),
            ':fname' => mb_substr(LicenseSkus::friendly($part), 0, 150),
            ':en'    => (int)($s['enabled'] ?? 0),
            ':cons'  => (int)($s['consumed'] ?? 0),
            ':susp'  => (int)($s['suspended'] ?? 0),
            ':warn'  => (int)($s['warning'] ?? 0),
            ':cap'   => mb_substr((string)($s['capabilityStatus'] ?? ''), 0, 50),
        ]);
        $nSku++;
    }

    $insAsg = $pdo->prepare(
        'INSERT IGNORE INTO license_assignments
            (tenant_id, user_principal_name, display_name, sku_id,
             sku_part_number, account_enabled, captured_at)
         VALUES (:t,:upn,:dn,:sid,:part,:en,NOW())'
    );
    $nAsg = 0;
    foreach ($assignments as $a) {
        $upn = (string)($a['userPrincipalName'] ?? '');
        $sid = (string)($a['skuId'] ?? '');
        if ($upn === '' || $sid === '') {
            continue;
        }
        $insAsg->execute([
            ':t'    => $tenant,
            ':upn'  => mb_substr($upn, 0, 255),
            ':dn'   => mb_substr((string)($a['displayName'] ?? ''), 0, 255),
            ':sid'  => $sid,
            ':part' => mb_substr($skuPart[$sid] ?? '', 0, 100),
            ':en'   => !empty($a['accountEnabled']) ? 1 : 0,
        ]);
        $nAsg++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['ok' => false, 'message' => 'Erro ao gravar: ' . $e->getMessage()], 500);
}

json_out([
    'ok'          => true,
    'tenant_id'   => $tenant,
    'skus'        => $nSku,
    'assignments' => $nAsg,
    'message'     => "Importado: {$nSku} SKU(s), {$nAsg} atribuicao(oes).",
]);
