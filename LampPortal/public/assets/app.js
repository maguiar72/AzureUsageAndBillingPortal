/* =====================================================================
 *  Portal Azure (LAMP) - logica do front-end
 *  Carrega dados dos endpoints JSON, desenha graficos (Chart.js) e
 *  controla o botao "Atualizar" (refresh).
 * ===================================================================== */
(function () {
    'use strict';

    var charts = {};
    var currentDays = 30;
    var pollTimer = null;

    var PALETTE = [
        '#0078d4', '#22c55e', '#f59e0b', '#ef4444',
        '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b'
    ];

    function $(sel) { return document.querySelector(sel); }

    function fmtMoney(value, currency) {
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
        loadBreakdowns(d);
    }

    function loadSummary(days) {
        getJSON('api/summary.php?days=' + days).then(function (res) {
            var s = res.body || {};
            $('#cardTotal').textContent = fmtMoney(s.total_cost, s.currency);
            $('#cardServices').textContent = s.service_count != null ? s.service_count : '—';
            $('#cardSubs').textContent = s.sub_count != null ? s.sub_count : '—';
            var last = s.last_extraction;
            $('#cardLast').textContent = last && last.finished_at
                ? formatDateTime(last.finished_at) + ' (' + last.status + ')'
                : 'sem dados ainda';
        }).catch(function () {
            toast('Nao foi possivel carregar o resumo.', 'err');
        });
    }

    function loadTimeseries(days) {
        getJSON('api/timeseries.php?days=' + days).then(function (res) {
            var series = (res.body && res.body.series) || [];
            var labels = series.map(function (r) { return r.usage_date; });
            var data = series.map(function (r) { return Number(r.cost); });
            drawLine('chartTimeseries', labels, data);
        });
    }

    function loadBreakdowns(days) {
        getJSON('api/by_service.php?days=' + days).then(function (res) {
            var b = res.body || {};
            drawDoughnut('chartService',
                (b.by_service || []).map(function (r) { return r.service_name; }),
                (b.by_service || []).map(function (r) { return Number(r.cost); }));
            drawBar('chartLocation',
                (b.by_location || []).map(function (r) { return r.resource_location; }),
                (b.by_location || []).map(function (r) { return Number(r.cost); }));
            fillSubsTable(b.by_subscription || []);
        });
    }

    function fillSubsTable(rows) {
        var tbody = $('#tableSubs tbody');
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="2" style="color:#64748b">Sem dados no periodo.</td></tr>';
            return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            var td1 = document.createElement('td');
            td1.textContent = r.display_name;
            var td2 = document.createElement('td');
            td2.className = 'num';
            td2.textContent = fmtMoney(r.cost, 'USD');
            tr.appendChild(td1); tr.appendChild(td2);
            tbody.appendChild(tr);
        });
    }

    /* ---------- Graficos ---------- */
    function ctx(id) { return document.getElementById(id).getContext('2d'); }

    function drawLine(id, labels, data) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx(id), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Custo diario', data: data,
                    borderColor: PALETTE[0], backgroundColor: 'rgba(0,120,212,.12)',
                    fill: true, tension: .3, pointRadius: 2
                }]
            },
            options: baseOptions(false)
        });
    }

    function drawBar(id, labels, data) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx(id), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: 'Custo', data: data, backgroundColor: PALETTE[0] }]
            },
            options: baseOptions(false)
        });
    }

    function drawDoughnut(id, labels, data) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(ctx(id), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: PALETTE }]
            },
            options: baseOptions(true)
        });
    }

    function baseOptions(isPie) {
        return {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: isPie, position: 'right', labels: { boxWidth: 12 } }
            },
            scales: isPie ? {} : {
                y: { beginAtZero: true, ticks: { callback: function (v) { return v; } } }
            }
        };
    }

    /* ---------- Refresh ---------- */
    function doRefresh() {
        var btn = $('#refreshBtn');
        btn.disabled = true;
        btn.classList.add('spinning');
        toast('Iniciando atualizacao dos dados...', 'info');

        getJSON('api/refresh.php', { method: 'POST' }).then(function (res) {
            var b = res.body || {};
            if (res.status === 200 && b.started === false) {
                // Rodou de forma sincrona: dados ja prontos.
                toast(b.message || 'Atualizado.', b.ok ? 'ok' : 'err');
                finishRefresh();
                if (b.ok) loadAll();
            } else if (b.ok || res.status === 202) {
                // Rodando em segundo plano: faz polling do status.
                toast(b.message || 'Atualizacao em andamento...', 'info');
                pollStatus();
            } else {
                toast(b.message || 'Nao foi possivel atualizar.', 'err');
                finishRefresh();
            }
        }).catch(function () {
            toast('Erro de rede ao atualizar.', 'err');
            finishRefresh();
        });
    }

    function pollStatus() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(function () {
            getJSON('api/status.php').then(function (res) {
                var b = res.body || {};
                if (b.is_running) {
                    pollStatus();
                } else {
                    finishRefresh();
                    var last = b.last || {};
                    toast(last.message || 'Atualizacao concluida.',
                          last.status === 'success' ? 'ok' : 'err');
                    loadAll();
                }
            }).catch(function () {
                finishRefresh();
                loadAll();
            });
        }, 3000);
    }

    function finishRefresh() {
        var btn = $('#refreshBtn');
        btn.disabled = false;
        btn.classList.remove('spinning');
    }

    /* ---------- Utils ---------- */
    function formatDateTime(sqlDt) {
        // sqlDt: "YYYY-MM-DD HH:MM:SS"
        if (!sqlDt) return '—';
        var iso = sqlDt.replace(' ', 'T');
        var d = new Date(iso);
        if (isNaN(d.getTime())) return sqlDt;
        return d.toLocaleString('pt-BR');
    }

    /* ---------- Init ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        $('#refreshBtn').addEventListener('click', doRefresh);
        $('#rangeSelect').addEventListener('change', function (e) {
            currentDays = parseInt(e.target.value, 10) || 30;
            loadAll();
        });
        // Chart.js carrega com "defer"; aguarda estar disponivel.
        var wait = setInterval(function () {
            if (window.Chart) { clearInterval(wait); loadAll(); }
        }, 50);
    });
})();
