/* =====================================================================
 *  Aba de Licenciamento M365 (protegida por login Entra ID).
 * ===================================================================== */
(function () {
    'use strict';

    var chart = null;
    var currentSku = '';
    var currentSkuName = '';
    var searchTimer = null;

    function $(s) { return document.querySelector(s); }
    function toast(msg, kind) {
        var t = $('#toast');
        t.textContent = msg; t.className = 'toast show ' + (kind || 'info');
        clearTimeout(t._timer); t._timer = setTimeout(function () { t.className = 'toast'; }, 6000);
    }
    function getJSON(url) {
        return fetch(url).then(function (r) {
            if (r.status === 401) {
                // Sessao expirou -> volta para o login.
                window.location.href = '/.auth/login/aad?post_login_redirect_uri=/licencas.php';
                throw new Error('auth');
            }
            return r.json();
        });
    }
    function td(text, cls) {
        var el = document.createElement('td');
        el.textContent = (text !== null && text !== undefined && text !== '') ? text : '—';
        if (cls) el.className = cls;
        return el;
    }
    function intBR(n) { return new Intl.NumberFormat('pt-BR').format(n || 0); }

    function loadSummary() {
        getJSON('api/licenses.php').then(function (d) {
            var t = d.totals || {};
            $('#cAcq').textContent = intBR(t.acquired);
            $('#cUse').textContent = intBR(t.consumed);
            $('#cAvail').textContent = intBR(t.available);
            $('#cUsers').textContent = intBR(t.user_count);
            $('#cSkus').textContent = intBR(t.sku_count);
            $('#cWhen').textContent = t.captured_at ? formatDateTime(t.captured_at) : 'sem dados';
            fillSkus(d.skus || []);
            drawSkuChart(d.skus || []);
        }).catch(function (e) { if (e.message !== 'auth') toast('Falha ao carregar licencas.', 'err'); });
    }

    function fillSkus(skus) {
        var tb = $('#tableSkus tbody');
        tb.innerHTML = '';
        if (!skus.length) {
            tb.innerHTML = '<tr><td colspan="6" class="muted">Sem dados de licenca ainda.</td></tr>';
            return;
        }
        skus.forEach(function (s) {
            var tr = document.createElement('tr');
            tr.className = 'clickable' + (currentSku === s.sku_id ? ' selected' : '');
            tr.title = 'Ver logins deste plano';
            tr.appendChild(td(s.friendly_name));
            tr.appendChild(td(s.sku_part_number));
            tr.appendChild(td(intBR(s.enabled), 'num'));
            tr.appendChild(td(intBR(s.consumed), 'num'));
            tr.appendChild(td(intBR(s.available), 'num'));
            tr.appendChild(td((s.usage_pct != null ? s.usage_pct : 0) + '%', 'num'));
            tr.addEventListener('click', function () {
                if (currentSku === s.sku_id) { currentSku = ''; currentSkuName = ''; }
                else { currentSku = s.sku_id; currentSkuName = s.friendly_name; }
                fillSkus(skus);
                loadUsers();
            });
            tb.appendChild(tr);
        });
    }

    function drawSkuChart(skus) {
        var labels = skus.map(function (s) { return s.friendly_name; });
        var acq = skus.map(function (s) { return s.enabled; });
        var use = skus.map(function (s) { return s.consumed; });
        if (chart) chart.destroy();
        chart = new Chart($('#chartSku').getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Adquiridas', data: acq, backgroundColor: '#94a3b8' },
                    { label: 'Em uso', data: use, backgroundColor: '#0078d4' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: { x: { stacked: false }, y: { beginAtZero: true } }
            }
        });
    }

    function loadUsers() {
        var q = $('#userSearch').value.trim();
        var url = 'api/license_users.php?limit=3000'
                + (currentSku ? '&sku=' + encodeURIComponent(currentSku) : '')
                + (q ? '&q=' + encodeURIComponent(q) : '');
        $('#usersScope').textContent = currentSku ? currentSkuName : 'todos';
        getJSON(url).then(function (d) {
            var rows = d.users || [];
            fillUsers(rows);
            $('#usersHint').textContent = rows.length
                ? ('Exibindo ' + rows.length + ' atribuicao(oes).'
                   + (rows.length >= 3000 ? ' Refine a busca.' : ''))
                : 'Nenhum login para o filtro atual.';
        }).catch(function (e) { if (e.message !== 'auth') toast('Falha ao carregar logins.', 'err'); });
    }

    function fillUsers(rows) {
        var tb = $('#tableUsers tbody');
        tb.innerHTML = '';
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="4" class="muted">Sem dados.</td></tr>';
            return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.appendChild(td(r.user_principal_name));
            tr.appendChild(td(r.display_name));
            tr.appendChild(td(r.friendly_name));
            var status = document.createElement('td');
            status.innerHTML = r.account_enabled
                ? '<span class="badge ok">ativa</span>'
                : '<span class="badge off">bloqueada</span>';
            tr.appendChild(status);
            tb.appendChild(tr);
        });
    }

    function formatDateTime(sqlDt) {
        if (!sqlDt) return '—';
        var d = new Date(sqlDt.replace(' ', 'T'));
        return isNaN(d.getTime()) ? sqlDt : d.toLocaleString('pt-BR');
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('#userSearch').addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadUsers, 350);
        });
        var wait = setInterval(function () {
            if (window.Chart) { clearInterval(wait); loadSummary(); loadUsers(); }
        }, 50);
    });
})();
