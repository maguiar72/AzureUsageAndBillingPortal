/* =====================================================================
 *  Aba de Licenciamento M365 (publica).
 *  - Contagens por plano: Organization.Read.All
 *  - Consulta por usuario / detalhamento: User.Read.All
 *  Degrada com elegancia enquanto as permissoes estao pendentes.
 * ===================================================================== */
(function () {
    'use strict';

    var chart = null;

    function $(s) { return document.querySelector(s); }
    function toast(msg, kind) {
        var t = $('#toast');
        t.textContent = msg; t.className = 'toast show ' + (kind || 'info');
        clearTimeout(t._timer); t._timer = setTimeout(function () { t.className = 'toast'; }, 6000);
    }
    function getJSON(url) { return fetch(url).then(function (r) { return r.json(); }); }
    function td(text, cls) {
        var el = document.createElement('td');
        el.textContent = (text !== null && text !== undefined && text !== '') ? text : '—';
        if (cls) el.className = cls;
        return el;
    }
    function intBR(n) { return new Intl.NumberFormat('pt-BR').format(n || 0); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    /* ---------- Resumo / contagens ---------- */
    function loadSummary() {
        getJSON('api/licenses.php').then(function (d) {
            var t = d.totals || {};
            $('#cAcq').textContent = intBR(t.acquired);
            $('#cUse').textContent = intBR(t.consumed);
            $('#cAvail').textContent = intBR(t.available);
            $('#cSkus').textContent = intBR(t.sku_count);
            $('#cWhen').textContent = t.captured_at ? formatDateTime(t.captured_at) : 'sem dados';
            fillSkus(d.skus || []);
            drawSkuChart(d.skus || []);
            renderStatus(d);
        }).catch(function () { toast('Falha ao carregar licencas.', 'err'); });
    }

    function renderStatus(d) {
        var el = $('#licStatus');
        var note = (d.status && d.status.note) ? d.status.note : '';
        if (!d.has_skus) {
            el.innerHTML = '⚠ Sem contagens de licenca ainda. Requer a permissao '
                + '<b>Organization.Read.All</b> na Managed Identity + execucao da coleta.'
                + (note ? '<br><small>Ultima coleta: ' + esc(note) + '</small>' : '');
            el.hidden = false;
        } else if (!d.has_users) {
            el.innerHTML = 'ℹ Contagens disponiveis. O <b>detalhamento por usuario</b> e a '
                + '<b>consulta por e-mail</b> requerem a permissao <b>User.Read.All</b> '
                + '(ainda pendente).'
                + (note ? '<br><small>Ultima coleta: ' + esc(note) + '</small>' : '');
            el.hidden = false;
        } else {
            el.hidden = true;
        }
    }

    // Colunas ordenaveis da tabela de planos.
    var SKU_COLS = [
        { key: 'friendly_name', label: 'Plano' },
        { key: 'sku_part_number', label: 'SKU' },
        { key: 'enabled', label: 'Adquiridas', num: true },
        { key: 'consumed', label: 'Em uso', num: true },
        { key: 'available', label: 'Disponiveis', num: true },
        { key: 'usage_pct', label: '% uso', num: true }
    ];
    var skuRows = [];
    var skuSort = { key: 'enabled', dir: 'desc' };

    function fillSkus(skus) {
        skuRows = (skus || []).slice();
        sortAndRenderSkus();
    }

    function sortAndRenderSkus() {
        var col = null;
        SKU_COLS.forEach(function (c) { if (c.key === skuSort.key) col = c; });
        var isNum = col && col.num;
        var dir = skuSort.dir === 'asc' ? 1 : -1;
        var k = skuSort.key;
        skuRows.sort(function (a, b) {
            var va = a[k], vb = b[k];
            if (isNum) { return (Number(va) - Number(vb)) * dir; }
            va = (va || '').toString().toLowerCase();
            vb = (vb || '').toString().toLowerCase();
            return va < vb ? -dir : (va > vb ? dir : 0);
        });

        var tb = $('#tableSkus tbody');
        tb.innerHTML = '';
        if (!skuRows.length) {
            tb.innerHTML = '<tr><td colspan="6" class="muted">Sem dados de plano ainda.</td></tr>';
        } else {
            skuRows.forEach(function (s) {
                var tr = document.createElement('tr');
                var nameTd = document.createElement('td');
                if (s.sku_id) {
                    var a = document.createElement('a');
                    a.href = 'plano.php?sku=' + encodeURIComponent(s.sku_id);
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.className = 'plan-link';
                    a.textContent = s.friendly_name || s.sku_part_number || '—';
                    nameTd.appendChild(a);
                } else {
                    nameTd.textContent = s.friendly_name || '—';
                }
                tr.appendChild(nameTd);
                tr.appendChild(td(s.sku_part_number));
                tr.appendChild(td(intBR(s.enabled), 'num'));
                tr.appendChild(td(intBR(s.consumed), 'num'));
                tr.appendChild(td(intBR(s.available), 'num'));
                tr.appendChild(td((s.usage_pct != null ? s.usage_pct : 0) + '%', 'num'));
                tb.appendChild(tr);
            });
        }
        SKU_COLS.forEach(function (c) {
            var th = document.querySelector('#tableSkus th[data-key="' + c.key + '"]');
            if (!th) return;
            var arrow = (skuSort.key === c.key) ? (skuSort.dir === 'asc' ? ' ▲' : ' ▼') : ' ⇅';
            th.textContent = c.label + arrow;
            th.classList.toggle('sorted', skuSort.key === c.key);
        });
    }

    function drawSkuChart(skus) {
        if (chart) chart.destroy();
        if (!skus.length) return;
        chart = new Chart($('#chartSku').getContext('2d'), {
            type: 'bar',
            data: {
                labels: skus.map(function (s) { return s.friendly_name; }),
                datasets: [
                    { label: 'Adquiridas', data: skus.map(function (s) { return s.enabled; }), backgroundColor: '#94a3b8' },
                    { label: 'Em uso', data: skus.map(function (s) { return s.consumed; }), backgroundColor: '#0078d4' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
        });
    }

    /* ---------- Consulta por usuario (ao vivo) ---------- */
    function doLookup(e) {
        e.preventDefault();
        var email = $('#lookupEmail').value.trim();
        var out = $('#lookupResult');
        if (!email) { out.innerHTML = '<p class="muted">Digite um e-mail.</p>'; return; }
        out.innerHTML = '<p class="muted">Consultando...</p>';
        getJSON('api/license_lookup.php?email=' + encodeURIComponent(email)).then(function (d) {
            if (!d.ok) { out.innerHTML = '<div class="warn">' + esc(d.message || 'Consulta indisponivel.') + '</div>'; return; }
            if (!d.found) { out.innerHTML = '<p class="muted">Usuario nao encontrado: ' + esc(email) + '</p>'; return; }
            var u = d.user;
            var badge = u.account_enabled ? '<span class="badge ok">ativa</span>' : '<span class="badge off">bloqueada</span>';
            var html = '<div class="lookup-card"><div class="lookup-head"><b>' + esc(u.display_name || u.user_principal_name)
                + '</b> &lt;' + esc(u.user_principal_name) + '&gt; ' + badge + '</div>';
            if (u.licenses && u.licenses.length) {
                html += '<ul class="lic-list">';
                u.licenses.forEach(function (l) { html += '<li>' + esc(l.friendly_name) + '</li>'; });
                html += '</ul>';
            } else {
                html += '<p class="muted">Nenhuma licenca atribuida.</p>';
            }
            html += '</div>';
            out.innerHTML = html;
        }).catch(function () { out.innerHTML = '<div class="warn">Erro de rede na consulta.</div>'; });
    }

    function formatDateTime(sqlDt) {
        if (!sqlDt) return '—';
        var d = new Date(sqlDt.replace(' ', 'T'));
        return isNaN(d.getTime()) ? sqlDt : d.toLocaleString('pt-BR');
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('#lookupForm').addEventListener('submit', doLookup);
        document.querySelectorAll('#tableSkus th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-key');
                if (skuSort.key === key) {
                    skuSort.dir = skuSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    skuSort.key = key;
                    skuSort.dir = (key === 'friendly_name' || key === 'sku_part_number') ? 'asc' : 'desc';
                }
                sortAndRenderSkus();
            });
        });
        var wait = setInterval(function () {
            if (window.Chart) { clearInterval(wait); loadSummary(); }
        }, 50);
    });
})();
