<?php
/**
 * Exportacao dos relatorios em HTML ou Excel (.xlsx).
 *
 *   export.php?format=html&days=30
 *   export.php?format=xlsx&days=365
 *
 * Inclui a visao por AREA DE NEGOCIO (nomes amigaveis) para o gestor.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../../src/bootstrap.php';

try {
    $db = new Database($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Falha ao conectar ao banco de dados.';
    exit;
}

$repo   = new ReportRepository($db);
$days   = filter_input(INPUT_GET, 'days', FILTER_VALIDATE_INT) ?: 30;
$days   = max(1, min(400, $days));
$format = strtolower((string)(filter_input(INPUT_GET, 'format') ?? 'html'));
$subRaw = (string)(filter_input(INPUT_GET, 'sub') ?? '');
$sub    = preg_match('/^[0-9a-fA-F-]{36}$/', $subRaw) ? $subRaw : '';

// ---- Coleta dos dados (limites generosos para exportacao) ----
$summary   = $repo->summary($days, $sub);
$byCat     = $repo->byCategory($days, $sub);
$byService = $repo->byService($days, 2000, $sub);
$byRG      = $repo->byResourceGroup($days, 2000, $sub);
$byType    = $repo->byResourceType($days, 2000, $sub);
$bySub     = $repo->bySubscription($days);
$series    = $repo->timeseries($days, $sub);
$resources = $repo->byResource($days, 5000, '', $sub);

$currency = $summary['currency'] ?? 'USD';
$siteName = $config['app']['site_name'] ?? 'Portal Azure';
$stamp    = date('Y-m-d H:i');
$fileTag  = 'relatorio-custos-' . $days . 'd-' . date('Y-m-d_H-i');

/** Formata dinheiro para exibicao (HTML). */
function money($v, string $cur): string
{
    $s = number_format((float)$v, 2, ',', '.');
    return $cur . ' ' . $s;
}

// =====================================================================
//  EXCEL (.xlsx)
// =====================================================================
if ($format === 'xlsx') {
    $x = new XlsxWriter();

    $x->addSheet('Resumo', [
        [$siteName],
        ['Relatorio gerado em', $stamp],
        ['Periodo (dias)', $days],
        ['Moeda', $currency],
        [],
        ['Custo total no periodo', (float)$summary['total_cost']],
        ['Custo no ultimo dia', (float)$summary['last_day_cost']],
        ['Data do ultimo dia', (string)($summary['last_day_date'] ?? '-')],
        ['Servicos', (int)$summary['service_count']],
        ['Resource groups', (int)$summary['rg_count']],
        ['Recursos', (int)$summary['resource_count']],
        ['Assinaturas', (int)$summary['sub_count']],
    ]);

    $catRows = [['Area de negocio', 'Custo (' . $currency . ')']];
    foreach ($byCat as $r) { $catRows[] = [$r['category'], (float)$r['cost']]; }
    $x->addSheet('Por area (gestor)', $catRows);

    $svcRows = [['Servico', 'Custo (' . $currency . ')']];
    foreach ($byService as $r) { $svcRows[] = [$r['service_name'], (float)$r['cost']]; }
    $x->addSheet('Por servico', $svcRows);

    $rgRows = [['Resource group', 'Custo (' . $currency . ')']];
    foreach ($byRG as $r) { $rgRows[] = [$r['resource_group'], (float)$r['cost']]; }
    $x->addSheet('Por resource group', $rgRows);

    $typeRows = [['Tipo de recurso', 'Custo (' . $currency . ')']];
    foreach ($byType as $r) { $typeRows[] = [$r['resource_type'], (float)$r['cost']]; }
    $x->addSheet('Por tipo', $typeRows);

    $subRows = [['Assinatura', 'Custo (' . $currency . ')']];
    foreach ($bySub as $r) { $subRows[] = [$r['display_name'], (float)$r['cost']]; }
    $x->addSheet('Por assinatura', $subRows);

    $dayRows = [['Data', 'Custo (' . $currency . ')']];
    foreach ($series as $r) { $dayRows[] = [$r['usage_date'], (float)$r['cost']]; }
    $x->addSheet('Por dia', $dayRows);

    $resRows = [['Recurso', 'Resource group', 'Tipo', 'Servico', 'Assinatura', 'Custo (' . $currency . ')']];
    foreach ($resources as $r) {
        $resRows[] = [
            $r['resource_name'], $r['resource_group'], $r['resource_type'],
            $r['service_name'], $r['subscription_id'], (float)$r['cost'],
        ];
    }
    $x->addSheet('Itens consumidos', $resRows);

    $bytes = $x->build();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileTag . '.xlsx"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

// =====================================================================
//  HTML (relatorio standalone)
// =====================================================================
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileTag . '.html"');
header('Cache-Control: no-store');

/** Renderiza uma tabela de 2 colunas (rotulo, custo). */
function tbl2(array $rows, string $col1, string $cur): string
{
    $h = '<table><thead><tr><th>' . htmlspecialchars($col1)
        . '</th><th class="num">Custo</th></tr></thead><tbody>';
    if (!$rows) { $h .= '<tr><td colspan="2" class="muted">Sem dados.</td></tr>'; }
    foreach ($rows as $r) {
        $label = $r[array_key_first($r)];
        $h .= '<tr><td>' . htmlspecialchars((string)$label) . '</td><td class="num">'
            . money($r['cost'], $cur) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

$catRows = array_map(fn($r) => ['label' => $r['category'], 'cost' => $r['cost']], $byCat);
$svcRows = array_map(fn($r) => ['label' => $r['service_name'], 'cost' => $r['cost']], $byService);
$rgRows  = array_map(fn($r) => ['label' => $r['resource_group'], 'cost' => $r['cost']], $byRG);
$subRows = array_map(fn($r) => ['label' => $r['display_name'], 'cost' => $r['cost']], $bySub);

echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8">';
echo '<title>' . htmlspecialchars($siteName) . ' - Relatorio</title><style>'
    . 'body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;margin:0;padding:32px;background:#fff}'
    . 'h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:28px 0 10px;border-bottom:2px solid #0078d4;padding-bottom:4px}'
    . '.sub{color:#64748b;font-size:13px;margin:0 0 8px}'
    . '.cards{display:flex;gap:16px;flex-wrap:wrap;margin:16px 0}'
    . '.card{border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;min-width:160px}'
    . '.card b{display:block;font-size:20px}.card span{color:#64748b;font-size:12px;text-transform:uppercase}'
    . 'table{border-collapse:collapse;width:100%;margin:6px 0 12px;font-size:13px}'
    . 'th,td{border:1px solid #e2e8f0;padding:7px 10px;text-align:left}'
    . 'th{background:#f1f5f9}.num{text-align:right;font-variant-numeric:tabular-nums}'
    . '.muted{color:#64748b}@media print{body{padding:0}}'
    . '</style></head><body>';

echo '<h1>' . htmlspecialchars($siteName) . '</h1>';
echo '<p class="sub">Relatorio de custos &middot; periodo: ultimos ' . $days
    . ' dias &middot; gerado em ' . htmlspecialchars($stamp) . ' &middot; moeda: ' . htmlspecialchars($currency) . '</p>';

echo '<div class="cards">'
    . '<div class="card"><span>Custo total</span><b>' . money($summary['total_cost'], $currency) . '</b></div>'
    . '<div class="card"><span>Ultimo dia (' . htmlspecialchars((string)($summary['last_day_date'] ?? '-')) . ')</span><b>' . money($summary['last_day_cost'], $currency) . '</b></div>'
    . '<div class="card"><span>Servicos</span><b>' . (int)$summary['service_count'] . '</b></div>'
    . '<div class="card"><span>Resource groups</span><b>' . (int)$summary['rg_count'] . '</b></div>'
    . '<div class="card"><span>Recursos</span><b>' . (int)$summary['resource_count'] . '</b></div>'
    . '<div class="card"><span>Assinaturas</span><b>' . (int)$summary['sub_count'] . '</b></div>'
    . '</div>';

echo '<h2>Custo por area de negocio (visao do gestor)</h2>' . tbl2($catRows, 'Area de negocio', $currency);
echo '<h2>Custo por servico</h2>' . tbl2($svcRows, 'Servico', $currency);
echo '<h2>Custo por resource group</h2>' . tbl2($rgRows, 'Resource group', $currency);
echo '<h2>Custo por assinatura</h2>' . tbl2($subRows, 'Assinatura', $currency);

echo '<h2>Itens consumidos (por recurso)</h2>';
echo '<table><thead><tr><th>Recurso</th><th>Resource group</th><th>Tipo</th><th>Servico</th><th class="num">Custo</th></tr></thead><tbody>';
if (!$resources) { echo '<tr><td colspan="5" class="muted">Sem dados.</td></tr>'; }
foreach ($resources as $r) {
    $type = $r['resource_type'];
    $shortType = $type !== '' ? substr(strrchr('/' . $type, '/'), 1) : '-';
    echo '<tr><td>' . htmlspecialchars((string)$r['resource_name']) . '</td>'
        . '<td>' . htmlspecialchars((string)$r['resource_group']) . '</td>'
        . '<td>' . htmlspecialchars($shortType) . '</td>'
        . '<td>' . htmlspecialchars((string)$r['service_name']) . '</td>'
        . '<td class="num">' . money($r['cost'], $currency) . '</td></tr>';
}
echo '</tbody></table>';

echo '<p class="sub">Fonte: Azure Cost Management API &middot; ' . htmlspecialchars($siteName) . '</p>';
echo '</body></html>';
