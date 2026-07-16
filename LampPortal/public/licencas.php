<?php
declare(strict_types=1);
$config = require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/api/_auth.php';

// Aba protegida: exige login Entra ID (Easy Auth). Anonimo -> redireciona.
$user = require_auth_page('/licencas.php');

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
            <span class="whoami" title="Usuario autenticado">👤 <?= htmlspecialchars($user, ENT_QUOTES) ?></span>
            <a class="btn-ghost" href="/.auth/logout">Sair</a>
        </div>
    </div>
</header>

<main class="wrap">
    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <section class="cards">
        <div class="card"><span class="card-label">Licencas adquiridas</span><span class="card-value" id="cAcq">—</span></div>
        <div class="card"><span class="card-label">Em uso</span><span class="card-value" id="cUse">—</span></div>
        <div class="card"><span class="card-label">Disponiveis</span><span class="card-value" id="cAvail">—</span></div>
        <div class="card"><span class="card-label">Usuarios com licenca</span><span class="card-value" id="cUsers">—</span></div>
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
            <div class="table-scroll">
                <table class="table" id="tableSkus">
                    <thead><tr>
                        <th>Plano</th><th>SKU</th>
                        <th class="num">Adquiridas</th><th class="num">Em uso</th>
                        <th class="num">Disponiveis</th><th class="num">% uso</th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <p class="hint">Clique num plano para ver os logins atribuidos.</p>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Logins atribuidos <span id="usersScope" class="tag">todos</span></h2>
                <span class="col-hint">Dados restritos - visiveis apenas a usuarios autenticados</span>
            </div>
            <input type="search" id="userSearch" class="search" placeholder="Buscar por login ou nome...">
        </div>
        <div class="table-scroll">
            <table class="table" id="tableUsers">
                <thead><tr>
                    <th>Login (UPN)</th><th>Nome</th><th>Licenca</th><th>Conta</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hint" id="usersHint"></p>
    </section>
</main>

<footer class="footer wrap">
    <p>Dados de licenciamento coletados do Microsoft Graph (subscribedSkus + usuarios).
       Acesso restrito por autenticacao Entra ID.</p>
</footer>

<script src="<?= htmlspecialchars($assetVer('assets/licencas.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
