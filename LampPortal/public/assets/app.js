/* =====================================================================
 *  Portal Azure (LAMP) - logica do front-end
 *  Carrega dados dos endpoints JSON, desenha graficos (Chart.js) e
 *  controla o botao "Atualizar" (refresh).
 * ===================================================================== */
(function () {
    'use strict';

    var charts = {};
    var currentDays = 30;
    var currency = 'USD';
    var pollTimer = null;
    var searchTimer = null;

    var PALETTE = [
        '#0078d4', '#22c55e', '#f59e0b', '#ef4444',
        '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b',
        '#14b8a6', '#a855f7', '#eab308', '#f43f5e', '#3b82f6'
    ];

    function $(sel) { return document.querySelector(sel); }

    function fmtMoney(value) {
        try {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency', currency: currency || 'USD'
            }).format(value || 0);
        } catch (e) {
            return (currency || 'USD') + ' ' + Number(value || 0).toFixed(2);
        }
    }

    function toast(msg, kind) {
        var t = $('#toast');
        t.textContent = msg;
        t.className = 'toast show ' + (kind || 'info');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.className = 'toast'; }, 6000);
    }

    function getJSON(url, opts) {
        return fetch(url, opts || {}).then(function (r) {
            return r.json().then(function (body) {
                return { status: r.status, body: body };
            });
        });
    }

    /* ---------- Carga de dados ---------- */
    function loadAll() {
        var d = currentDays;
        loadSummary(d);
        loadTimeseries(d);
        loadBreakdown(d);
        loadResources();
    }

    function loadSummary(days) {
        getJSON('api/summary.php?days=' + days).then(function (res) {
            var s = res.body || {};
            currency = s.currency || 'USD';
            $('#cardTotal').textContent = fmtMoney(s.total_cost);
            $('#cardLastDay').textContent = fmtMoney(s.last_day_cost);
            $('#cardLastDayDate').textContent = s.last_day_date ? ('em ' + s.last_day_date) : '';
            $('#cardServices').textContent = num(s.service_count);
            $('#cardRGs').textContent = num(s.rg_count);
            $('#cardResources').textContent = num(s.resource_count);
            $('#cardSubs').textContent = num(s.sub_count);
            var last = s.last_extraction;
            var cardLast = $('#cardLast');
            cardLast.textContent = last && last.finished_at
                ? formatDateTime(last.finished_at) + ' (' + last.status + ')'
                : 'sem dados ainda';
            cardLast.title = last && last.message ? last.message : '';

            // Aviso persistente quando a ultima extracao falhou (mostra o motivo).
            var warn = $('#extractionWarn');
            if (last && last.status === 'error') {
                warn.textContent = '⚠ Ultima extracao com erro: ' + (last.message || 'motivo desconhecido');
                warn.hidden = false;
            } else {
                warn.hidden = true;
            }
        }).catch(function () { toast('Nao foi possivel carregar o resumo.', 'err'); });
    }

    function loadTimeseries(days) {
        getJSON('api/timeseries.php?days=' + days).then(function (res) {
            var series = (res.body && res.body.series) || [];
            drawLine('chartTimeseries',
                series.map(function (r) { return r.usage_date; }),
                series.map(function (r) { return Number(r.cost); }));
        });
    }

    function loadBreakdown(days) {
        getJSON('api/breakdown.php?days=' + days).then(function (res) {
            var b = res.body || {};
            drawBar('chartCategory',
                pluck(b.by_category, 'category'), pluck(b.by_category, 'cost'), false);
            drawDoughnut('chartService',
                pluck(b.by_service, 'service_name'), pluck(b.by_service, 'cost'));
            drawBar('chartRG',
                pluck(b.by_resource_group, 'resource_group'), pluck(b.by_resource_group, 'cost'));
            drawBar('chartType',
                pluck(b.by_resource_type, 'resource_type'), pluck(b.by_resource_type, 'cost'));
            fillSubsTable(b.by_subscription || []);
        });
    }

    function loadResources() {
        var q = $('#resSearch').value.trim();
        var url = 'api/resources.php?days=' + currentDays + '&limit=200'
                + (q ? '&q=' + encodeURIComponent(q) : '');
        getJSON(url).then(function (res) {
            var rows = (res.body && res.body.resources) || [];
            fillResourcesTable(rows);
            $('#resHint').textContent = rows.length
                ? ('Exibindo ' + rows.length + ' recurso(s), ordenados por custo.'
                   + (rows.length >= 200 ? ' Refine a busca para ver itens especificos.' : ''))
                : 'Nenhum recurso encontrado para o filtro/periodo.';
        });
    }

    /* ---------- Tabelas ---------- */
    function pluck(arr, key) {
        return (arr || []).map(function (r) {
            return key === 'cost' ? Number(r[key]) : r[key];
        });
    }
    function num(v) { return v != null ? v : '—'; }

    function fillSubsTable(rows) {
        var tbody = $('#tableSubs tbody');
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="muted">Sem dados no periodo.</td></tr>';
            return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.appendChild(td(r.display_name));
            tr.appendChild(td(fmtMoney(r.cost), 'num'));
            tbody.appendChild(tr);
        });
    }

    // Colunas ordenaveis da tabela de recursos.
    var RES_COLS = [
        { key: 'resource_name',  label: 'Recurso' },
        { key: 'resource_group', label: 'Resource group' },
        { key: 'resource_type',  label: 'Tipo' },
        { key: 'service_name',   label: 'Servico' },
        { key: 'cost',           label: 'Custo', num: true }
    ];
    var resourceRows = [];
    var resSort = { key: 'cost', dir: 'desc' };

    function fillResourcesTable(rows) {
        resourceRows = (rows || []).slice();
        sortAndRenderResources();
    }

    function sortAndRenderResources() {
        var k = resSort.key;
        var dir = resSort.dir === 'asc' ? 1 : -1;
        var isNum = k === 'cost';
        resourceRows.sort(function (a, b) {
            var va = a[k], vb = b[k];
            if (isNum) { return (Number(va) - Number(vb)) * dir; }
            va = (va || '').toString().toLowerCase();
            vb = (vb || '').toString().toLowerCase();
            return va < vb ? -dir : (va > vb ? dir : 0);
        });
        renderResourceRows(resourceRows);
        updateSortIndicators();
    }

    function renderResourceRows(rows) {
        var tbody = $('#tableResources tbody');
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">Sem dados.</td></tr>';
            return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.appendChild(td(r.resource_name));
            tr.appendChild(td(r.resource_group));
            tr.appendChild(td(shortType(r.resource_type)));
            tr.appendChild(td(r.service_name));
            tr.appendChild(td(fmtMoney(r.cost), 'num'));
            tbody.appendChild(tr);
        });
    }

    function updateSortIndicators() {
        RES_COLS.forEach(function (c) {
            var th = document.querySelector('#tableResources th[data-key="' + c.key + '"]');
            if (!th) return;
            // Coluna ativa: ▲/▼. Demais: ⇅ (indica que da para ordenar).
            var arrow = (resSort.key === c.key)
                ? (resSort.dir === 'asc' ? ' ▲' : ' ▼')
                : ' ⇅';
            th.textContent = c.label + arrow;
            th.classList.toggle('sorted', resSort.key === c.key);
        });
    }

    /* ---------- Drill-down: clicar num grafico filtra a tabela ---------- */
    function drillTo(term) {
        if (!term) return;
        var box = $('#resSearch');
        box.value = term;
        loadResources();
        toast('Filtrando itens por: ' + term, 'info');
        var panel = document.getElementById('tableResources');
        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function chartClick(chart, elements) {
        if (elements && elements.length) {
            var label = chart.data.labels[elements[0].index];
            drillTo(label);
        }
    }

    function td(text, cls) {
        var el = document.createElement('td');
        el.textContent = text != null && text !== '' ? text : '—';
        if (cls) el.className = cls;
        return el;
    }

    // "Microsoft.Compute/virtualMachines" -> "virtualMachines"
    function shortType(t) {
        if (!t) return '—';
        var parts = t.split('/');
        return parts[parts.length - 1];
    }

    /* ---------- Graficos ---------- */
    function ctx(id) { return document.getElementById(id).getContext('2d'); }

    function drawLine(id, labels, data) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx(id), {
            type: 'line',
            data: { labels: labels, datasets: [{
                label: 'Custo diario', data: data,
                borderColor: PALETTE[0], backgroundColor: 'rgba(0,120,212,.12)',
                fill: true, tension: .3, pointRadius: 2
            }] },
            options: baseOptions(false)
        });
    }

    function drawBar(id, labels, data, drill) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx(id), {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: 'Custo', data: data, backgroundColor: PALETTE[0] }] },
            options: baseOptions(false, drill !== false)
        });
    }

    function drawDoughnut(id, labels, data) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx(id), {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: PALETTE }] },
            options: baseOptions(true, true)
        });
    }

    function baseOptions(isPie, drill) {
        var money = function (v) { return fmtMoney(v); };
        var opts = {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: isPie, position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: function (c) {
                    var v = isPie ? c.parsed : c.parsed.y;
                    return ' ' + money(v);
                } } }
            },
            scales: isPie ? {} : { y: { beginAtZero: true } }
        };
        if (drill) {
            // Clique numa fatia/barra -> filtra a tabela de itens.
            opts.onClick = function (evt, elements, chart) { chartClick(chart, elements); };
            opts.onHover = function (evt, elements) {
                evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
            };
        }
        return opts;
    }

    /* ---------- Refresh ---------- */
    function doRefresh() {
        var btn = $('#refreshBtn');
        btn.disabled = true; btn.classList.add('spinning');
        toast('Iniciando atualizacao dos dados...', 'info');
        getJSON('api/refresh.php', { method: 'POST' }).then(function (res) {
            var b = res.body || {};
            if (res.status === 200 && b.started === false) {
                toast(b.message || 'Atualizado.', b.ok ? 'ok' : 'err');
                finishRefresh();
                if (b.ok) loadAll();
            } else if (b.ok || res.status === 202) {
                toast(b.message || 'Atualizacao em andamento...', 'info');
                pollStatus();
            } else {
                toast(b.message || 'Nao foi possivel atualizar.', 'err');
                finishRefresh();
            }
        }).catch(function () { toast('Erro de rede ao atualizar.', 'err'); finishRefresh(); });
    }

    function pollStatus() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(function () {
            getJSON('api/status.php').then(function (res) {
                var b = res.body || {};
                if (b.is_running) { pollStatus(); }
                else {
                    finishRefresh();
                    var last = b.last || {};
                    toast(last.message || 'Atualizacao concluida.',
                          last.status === 'success' ? 'ok' : 'err');
                    loadAll();
                }
            }).catch(function () { finishRefresh(); loadAll(); });
        }, 3000);
    }

    function finishRefresh() {
        var btn = $('#refreshBtn');
        btn.disabled = false; btn.classList.remove('spinning');
    }

    /* ---------- Utils ---------- */
    function formatDateTime(sqlDt) {
        if (!sqlDt) return '—';
        var d = new Date(sqlDt.replace(' ', 'T'));
        return isNaN(d.getTime()) ? sqlDt : d.toLocaleString('pt-BR');
    }

    /* ---------- Init ---------- */
    function downloadExport(fmt) {
        window.location.href = 'api/export.php?format=' + fmt + '&days=' + currentDays;
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('#refreshBtn').addEventListener('click', doRefresh);
        $('#exportHtml').addEventListener('click', function () { downloadExport('html'); });
        $('#exportXlsx').addEventListener('click', function () { downloadExport('xlsx'); });
        $('#rangeSelect').addEventListener('change', function (e) {
            currentDays = parseInt(e.target.value, 10) || 30;
            loadAll();
        });
        $('#resSearch').addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadResources, 350);
        });
        // Ordenacao por coluna (clique no cabecalho da tabela de recursos).
        document.querySelectorAll('#tableResources th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-key');
                if (resSort.key === key) {
                    resSort.dir = resSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    resSort.key = key;
                    resSort.dir = (key === 'cost') ? 'desc' : 'asc';
                }
                sortAndRenderResources();
            });
        });
        var wait = setInterval(function () {
            if (window.Chart) { clearInterval(wait); loadAll(); }
        }, 50);
    });
})();
