<?php
declare(strict_types=1);
$config = require __DIR__ . '/../src/bootstrap.php';

// Aba PUBLICA (sem login). As contagens exigem Organization.Read.All e o
// detalhamento por usuario exige User.Read.All na Managed Identity.
$siteName = htmlspecialchars($config['app']['site_name'] ?? 'Portal Azure', ENT_QUOTES);
$assetVer = static function (string $rel): string {
    $path = __DIR__ . '/' . $rel;
    $v = is_file($path) ? (string)filemtime($path) : '1';
    return $rel . '?v=' . $v;
};
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $siteName ?> - Licenciamento</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetVer('assets/style.css'), ENT_QUOTES) ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <div class="brand">
            <span class="logo">🔑</span>
            <div>
                <h1><?= $siteName ?></h1>
                <p class="subtitle">Licenciamento Microsoft 365</p>
            </div>
        </div>
        <div class="controls">
            <nav class="topnav">
                <a href="index.php">Custos Azure</a>
                <a href="licencas.php" class="active">Licenciamento</a>
            </nav>
        </div>
    </div>
</header>

<main class="wrap">
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <div id="licStatus" class="warn" role="status" hidden></div>

    <section class="cards">
        <div class="card"><span class="card-label">Licencas adquiridas</span><span class="card-value" id="cAcq">—</span></div>
        <div class="card"><span class="card-label">Em uso</span><span class="card-value" id="cUse">—</span></div>
        <div class="card"><span class="card-label">Disponiveis</span><span class="card-value" id="cAvail">—</span></div>
        <div class="card"><span class="card-label">Planos (SKUs)</span><span class="card-value" id="cSkus">—</span></div>
        <div class="card"><span class="card-label">Ultima coleta</span><span class="card-value small" id="cWhen">—</span></div>
    </section>

    <div class="grid-2">
        <section class="panel">
            <h2>Adquiridas x Em uso por plano</h2>
            <div class="chart-box"><canvas id="chartSku"></canvas></div>
        </section>
        <section class="panel">
            <h2>Planos de licenca</h2>
            <span class="col-hint">↕ Clique nos cabecalhos para ordenar &middot; licencas gratuitas/ilimitadas ocultas</span>
            <div class="table-scroll">
                <table class="table" id="tableSkus">
                    <thead><tr>
                        <th class="sortable" data-key="friendly_name">Plano</th>
                        <th class="sortable" data-key="sku_part_number">SKU</th>
                        <th class="sortable num" data-key="enabled">Adquiridas</th>
                        <th class="sortable num" data-key="consumed">Em uso</th>
                        <th class="sortable num" data-key="available">Disponiveis</th>
                        <th class="sortable num" data-key="usage_pct">% uso</th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Consulta por usuario (login / e-mail)</h2>
                <span class="col-hint">Digite o e-mail e veja as licencas atribuidas a esse usuario (consulta ao vivo).</span>
            </div>
            <form id="lookupForm" class="lookup">
                <input type="email" id="lookupEmail" class="search" placeholder="fulano@trf3.jus.br" autocomplete="off">
                <button type="submit" class="btn-refresh"><span class="label">Consultar</span></button>
            </form>
        </div>
        <div id="lookupResult" class="lookup-result"></div>
    </section>
</main>

<footer class="footer wrap">
    <p>Dados de licenciamento do Microsoft Graph. Clique no nome de um plano para ver
       suas funcionalidades. A consulta por e-mail e feita sob demanda &mdash; as
       identidades nao sao listadas em massa.</p>
</footer>

<script src="<?= htmlspecialchars($assetVer('assets/licencas.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
