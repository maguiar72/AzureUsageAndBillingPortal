/* =====================================================================
 *  Aba de Licenciamento M365 (publica).
 *  - Contagens por plano: Organization.Read.All
 *  - Consulta por usuario / detalhamento: User.Read.All
 *  Degrada com elegancia enquanto as permissoes estao pendentes.
 * ===================================================================== */
(function () {
    'use strict';

    var chart = null;
    var currentSku = '';
    var currentSkuName = '';
    var searchTimer = null;
    var detailLoaded = false;

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

    function fillSkus(skus) {
        var tb = $('#tableSkus tbody');
        tb.innerHTML = '';
        if (!skus.length) {
            tb.innerHTML = '<tr><td colspan="6" class="muted">Sem dados de plano ainda.</td></tr>';
            return;
        }
        skus.forEach(function (s) {
            var tr = document.createElement('tr');
            tr.appendChild(td(s.friendly_name));
            tr.appendChild(td(s.sku_part_number));
            tr.appendChild(td(intBR(s.enabled), 'num'));
            tr.appendChild(td(intBR(s.consumed), 'num'));
            tr.appendChild(td(intBR(s.available), 'num'));
            tr.appendChild(td((s.usage_pct != null ? s.usage_pct : 0) + '%', 'num'));
            tb.appendChild(tr);
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

    /* ---------- Detalhamento opcional (todos os logins) ---------- */
    function loadUsers() {
        var q = $('#userSearch').value.trim();
        var url = 'api/license_users.php?limit=3000' + (q ? '&q=' + encodeURIComponent(q) : '');
        getJSON(url).then(function (d) {
            var rows = d.users || [];
            var tb = $('#tableUsers tbody');
            tb.innerHTML = '';
            if (!rows.length) {
                tb.innerHTML = '<tr><td colspan="4" class="muted">Sem dados (requer User.Read.All + coleta).</td></tr>';
            } else {
                rows.forEach(function (r) {
                    var tr = document.createElement('tr');
                    tr.appendChild(td(r.user_principal_name));
                    tr.appendChild(td(r.display_name));
                    tr.appendChild(td(r.friendly_name));
                    var st = document.createElement('td');
                    st.innerHTML = r.account_enabled ? '<span class="badge ok">ativa</span>' : '<span class="badge off">bloqueada</span>';
                    tr.appendChild(st);
                    tb.appendChild(tr);
                });
            }
            $('#usersHint').textContent = rows.length
                ? ('Exibindo ' + rows.length + ' atribuicao(oes).' + (rows.length >= 3000 ? ' Refine a busca.' : ''))
                : '';
        });
    }

    function formatDateTime(sqlDt) {
        if (!sqlDt) return '—';
        var d = new Date(sqlDt.replace(' ', 'T'));
        return isNaN(d.getTime()) ? sqlDt : d.toLocaleString('pt-BR');
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('#lookupForm').addEventListener('submit', doLookup);
        $('#loadDetail').addEventListener('click', function () {
            $('#detailWrap').hidden = false;
            this.hidden = true;
            if (!detailLoaded) { detailLoaded = true; loadUsers(); }
        });
        $('#userSearch').addEventListener('input', function () {
            clearTimeout(searchTimer); searchTimer = setTimeout(loadUsers, 350);
        });
        var wait = setInterval(function () {
            if (window.Chart) { clearInterval(wait); loadSummary(); }
        }, 50);
    });
})();
