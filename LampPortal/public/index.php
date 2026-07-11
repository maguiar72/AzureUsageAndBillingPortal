<?php
declare(strict_types=1);
$config = require __DIR__ . '/../src/bootstrap.php';
$siteName = htmlspecialchars($config['app']['site_name'] ?? 'Portal Azure', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $siteName ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <div class="brand">
            <span class="logo">☁</span>
            <div>
                <h1><?= $siteName ?></h1>
                <p class="subtitle">Relatorios publicos de uso e faturamento da Azure</p>
            </div>
        </div>
        <div class="controls">
            <select id="rangeSelect" aria-label="Periodo">
                <option value="1">Ultimo dia</option>
                <option value="7">Ultimos 7 dias</option>
                <option value="30" selected>Ultimos 30 dias</option>
                <option value="60">Ultimos 60 dias</option>
                <option value="90">Ultimos 90 dias</option>
                <option value="180">Ultimos 180 dias</option>
                <option value="365">Ultimos 12 meses</option>
            </select>
            <button id="refreshBtn" class="btn-refresh" type="button">
                <span class="icon">⟳</span> <span class="label">Atualizar</span>
            </button>
        </div>
    </div>
</header>

<main class="wrap">
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <section class="cards" id="cards">
        <div class="card">
            <span class="card-label">Custo total no periodo</span>
            <span class="card-value" id="cardTotal">—</span>
        </div>
        <div class="card">
            <span class="card-label">Custo no ultimo dia</span>
            <span class="card-value" id="cardLastDay">—</span>
            <span class="card-sub" id="cardLastDayDate"></span>
        </div>
        <div class="card">
            <span class="card-label">Servicos</span>
            <span class="card-value" id="cardServices">—</span>
        </div>
        <div class="card">
            <span class="card-label">Resource groups</span>
            <span class="card-value" id="cardRGs">—</span>
        </div>
        <div class="card">
            <span class="card-label">Recursos</span>
            <span class="card-value" id="cardResources">—</span>
        </div>
        <div class="card">
            <span class="card-label">Assinaturas</span>
            <span class="card-value" id="cardSubs">—</span>
        </div>
        <div class="card">
            <span class="card-label">Ultima atualizacao</span>
            <span class="card-value small" id="cardLast">—</span>
        </div>
    </section>

    <section class="panel">
        <h2>Custo por dia</h2>
        <div class="chart-box"><canvas id="chartTimeseries"></canvas></div>
    </section>

    <div class="grid-2">
        <section class="panel">
            <h2>Custo por servico</h2>
            <div class="chart-box"><canvas id="chartService"></canvas></div>
        </section>
        <section class="panel">
            <h2>Custo por resource group</h2>
            <div class="chart-box"><canvas id="chartRG"></canvas></div>
        </section>
    </div>

    <div class="grid-2">
        <section class="panel">
            <h2>Custo por tipo de recurso</h2>
            <div class="chart-box"><canvas id="chartType"></canvas></div>
        </section>
        <section class="panel">
            <h2>Custo por assinatura</h2>
            <table class="table" id="tableSubs">
                <thead><tr><th>Assinatura</th><th class="num">Custo</th></tr></thead>
                <tbody></tbody>
            </table>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Itens consumidos (por recurso)</h2>
            <input type="search" id="resSearch" class="search"
                   placeholder="Filtrar por recurso, grupo, tipo ou servico...">
        </div>
        <div class="table-scroll">
            <table class="table" id="tableResources">
                <thead>
                    <tr>
                        <th class="sortable" data-key="resource_name">Recurso</th>
                        <th class="sortable" data-key="resource_group">Resource group</th>
                        <th class="sortable" data-key="resource_type">Tipo</th>
                        <th class="sortable" data-key="service_name">Servico</th>
                        <th class="sortable num" data-key="cost">Custo</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hint" id="resHint"></p>
    </section>
</main>

<footer class="footer wrap">
    <p>Dados extraidos automaticamente a cada 12 horas via Azure Cost Management API
       (detalhe por recurso, dia e resource group).
       Portal LAMP baseado no projeto Azure Usage &amp; Billing Insights.</p>
</footer>

<script src="assets/app.js" defer></script>
</body>
</html>
