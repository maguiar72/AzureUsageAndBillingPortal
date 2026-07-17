<?php
declare(strict_types=1);
$config = require __DIR__ . '/../src/bootstrap.php';

// Pagina PUBLICA de detalhe de um plano (SKU): abre em nova aba a partir da
// aba de Licenciamento e lista todas as funcionalidades (service plans).
$siteName = htmlspecialchars($config['app']['site_name'] ?? 'Portal Azure', ENT_QUOTES);
$assetVer = static function (string $rel): string {
    $path = __DIR__ . '/' . $rel;
    $v = is_file($path) ? (string)filemtime($path) : '1';
    return $rel . '?v=' . $v;
};

$skuId = (string)(filter_input(INPUT_GET, 'sku') ?? '');
$detail = null;
if (preg_match('/^[0-9a-fA-F-]{36}$/', $skuId)) {
    $repo = new LicenseRepository(new Database($config['db']));
    $detail = $repo->skuDetail($skuId);
}

$title = $detail ? ($detail['friendly_name'] ?: $detail['sku_part_number']) : 'Plano nao encontrado';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?> - <?= $siteName ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetVer('assets/style.css'), ENT_QUOTES) ?>">
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <div class="brand">
            <span class="logo">🔑</span>
            <div>
                <h1><?= $siteName ?></h1>
                <p class="subtitle">Detalhe do plano de licenca</p>
            </div>
        </div>
        <div class="controls">
            <nav class="topnav">
                <a href="index.php">Custos Azure</a>
                <a href="licencas.php">Licenciamento</a>
            </nav>
        </div>
    </div>
</header>

<main class="wrap">
<?php if (!$detail): ?>
    <section class="panel">
        <h2>Plano nao encontrado</h2>
        <p class="muted">Nao ha detalhes para o plano solicitado. Ele pode nao ter sido
           coletado ainda ou o identificador e invalido.</p>
        <p><a class="btn-ghost" href="licencas.php">&larr; Voltar ao licenciamento</a></p>
    </section>
<?php else:
    $ptPlans = 0;
    foreach ($detail['plans'] as $p) {
        if (strcasecmp($p['status'], 'Success') === 0) { $ptPlans++; }
    }
?>
    <section class="plan-hero">
        <div>
            <span class="col-hint"><a href="licencas.php">&larr; Licenciamento</a></span>
            <h2><?= htmlspecialchars($detail['friendly_name'] ?: $detail['sku_part_number'], ENT_QUOTES) ?></h2>
            <span class="plan-sku"><?= htmlspecialchars($detail['sku_part_number'], ENT_QUOTES) ?></span>
        </div>
    </section>

    <section class="cards">
        <div class="card"><span class="card-label">Adquiridas</span><span class="card-value"><?= number_format($detail['enabled'], 0, ',', '.') ?></span></div>
        <div class="card"><span class="card-label">Em uso</span><span class="card-value"><?= number_format($detail['consumed'], 0, ',', '.') ?></span></div>
        <div class="card"><span class="card-label">Disponiveis</span><span class="card-value"><?= number_format($detail['available'], 0, ',', '.') ?></span></div>
        <div class="card"><span class="card-label">% uso</span><span class="card-value"><?= htmlspecialchars((string)$detail['usage_pct'], ENT_QUOTES) ?>%</span></div>
        <div class="card"><span class="card-label">Funcionalidades</span><span class="card-value"><?= count($detail['plans']) ?></span></div>
    </section>

    <section class="panel">
        <h2>Funcionalidades incluidas neste plano</h2>
        <span class="col-hint">Cada funcionalidade (service plan) que compoe o plano e seu estado de provisionamento.</span>
        <?php if (!$detail['plans']): ?>
            <p class="muted">Sem detalhamento de funcionalidades. Execute a coleta (PowerShell)
               novamente para popular os service plans deste plano.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table roomy" id="tablePlans">
                    <thead><tr>
                        <th>Funcionalidade</th>
                        <th>Nome tecnico</th>
                        <th>Estado</th>
                        <th>Aplica-se a</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($detail['plans'] as $p):
                        $ok = strcasecmp($p['status'], 'Success') === 0;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($p['friendly'], ENT_QUOTES) ?></td>
                            <td class="mono muted"><?= htmlspecialchars($p['name'], ENT_QUOTES) ?></td>
                            <td><?= $ok
                                ? '<span class="badge ok">ativo</span>'
                                : '<span class="badge off">' . htmlspecialchars($p['status'] ?: '—', ENT_QUOTES) . '</span>' ?></td>
                            <td class="muted"><?= htmlspecialchars($p['applies'] ?: '—', ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</main>

<footer class="footer wrap">
    <p>Dados de licenciamento do Microsoft Graph. Aba publica &mdash; sem identidades de usuario.</p>
</footer>
</body>
</html>
