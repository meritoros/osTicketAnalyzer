/* Front dashboardu: pobiera dane z api.php (JSON) i rysuje tabelę oraz wykresy.
   Przeglądarka NIE łączy się z bazą — dostaje tylko policzone wyniki. */
'use strict';

const $ = (sel) => document.querySelector(sel);

const charts = {}; // uchwyty Chart.js, żeby móc je odświeżać

// --- Pomocnicze ------------------------------------------------------------

function qs(params) {
    const p = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') p.append(k, v);
    });
    return p.toString();
}

async function api(report, params = {}) {
    const url = 'api.php?' + qs(Object.assign({ report }, params));
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (res.status === 401) {
        window.location.href = 'login.php';
        return { data: [] };
    }
    const json = await res.json();
    if (json.error) throw new Error(json.error);
    return json;
}

function currentFilters() {
    return { from: $('#f-from').value, to: $('#f-to').value };
}

/** Sekundy -> "2 g 15 min" / "45 min" / "30 s". */
function fmtDuration(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    seconds = Number(seconds);
    if (!isFinite(seconds) || seconds < 0) return '—';
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    if (d > 0) return `${d} d ${h} g`;
    if (h > 0) return `${h} g ${m} min`;
    if (m > 0) return `${m} min`;
    return `${s} s`;
}

function fmtDateTime(v) {
    return v ? String(v) : '—';
}

function esc(v) {
    return String(v ?? '').replace(/[&<>"]/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

// --- Raport główny ---------------------------------------------------------

let lastMainRows = [];

async function loadMain() {
    const priorityId = $('#f-priority').value;
    const tbody = $('#main-table tbody');
    if (!priorityId) {
        tbody.innerHTML = '<tr><td colspan="8" class="muted">Wybierz priorytet.</td></tr>';
        return;
    }
    tbody.innerHTML = '<tr><td colspan="8" class="muted">Ładowanie…</td></tr>';
    try {
        const { data } = await api('high_priority_closed',
            Object.assign({ priority_id: priorityId }, currentFilters()));
        lastMainRows = data;

        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="muted">Brak zgłoszeń dla wybranych kryteriów.</td></tr>';
            $('#main-summary').textContent = '';
            return;
        }

        const withResp = data.filter((r) => r.first_response_seconds !== null);
        const avg = withResp.length
            ? Math.round(withResp.reduce((a, r) => a + Number(r.first_response_seconds), 0) / withResp.length)
            : null;
        $('#main-summary').textContent =
            `Zgłoszeń: ${data.length} · z odpowiedzią: ${withResp.length} · średni czas do 1. odpowiedzi: ${fmtDuration(avg)}`;

        tbody.innerHTML = data.map((r) => `
            <tr>
                <td>${esc(r.number)}</td>
                <td>${esc(r.subject || '')}</td>
                <td>${esc(r.submitter || '')}</td>
                <td>${esc(r.agent || '')}</td>
                <td>${esc(fmtDateTime(r.opened_at))}</td>
                <td>${esc(fmtDateTime(r.first_response_at))}</td>
                <td>${esc(fmtDuration(r.first_response_seconds))}</td>
                <td>${esc(fmtDateTime(r.closed_at))}</td>
            </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" class="error">Błąd: ${esc(e.message)}</td></tr>`;
    }
}

function exportCsv() {
    if (!lastMainRows.length) {
        alert('Brak danych do eksportu — najpierw pokaż raport.');
        return;
    }
    const headers = ['Nr', 'Temat', 'Zglaszajacy', 'Agent',
        'Data zgloszenia', 'Pierwsza odpowiedz', 'Czas do 1. odpowiedzi (s)', 'Data zamkniecia'];
    const rows = lastMainRows.map((r) => [
        r.number, r.subject || '', r.submitter || '', r.agent || '',
        r.opened_at || '', r.first_response_at || '',
        r.first_response_seconds ?? '', r.closed_at || '',
    ]);
    const csv = [headers, ...rows]
        .map((row) => row.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';'))
        .join('\r\n');
    // BOM, by Excel poprawnie odczytał polskie znaki
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'raport_priorytet.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}

// --- Wykresy ---------------------------------------------------------------

function drawChart(id, config) {
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart($('#' + id).getContext('2d'), config);
}

async function loadCharts() {
    const f = currentFilters();

    try {
        const { data } = await api('volume', f);
        drawChart('chart-volume', {
            type: 'line',
            data: {
                labels: data.map((r) => r.day),
                datasets: [
                    { label: 'Wszystkie', data: data.map((r) => Number(r.total)), tension: 0.25 },
                    { label: 'Zamknięte', data: data.map((r) => Number(r.closed)), tension: 0.25 },
                ],
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
        });
    } catch (e) { console.error('volume', e); }

    try {
        const { data } = await api('by_priority', f);
        drawChart('chart-priority', {
            type: 'doughnut',
            data: {
                labels: data.map((r) => r.priority_name || '(brak)'),
                datasets: [{ data: data.map((r) => Number(r.total)) }],
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
        });
    } catch (e) { console.error('by_priority', e); }

    try {
        const { data } = await api('by_agent', f);
        drawChart('chart-agent', {
            type: 'bar',
            data: {
                labels: data.map((r) => r.agent || '(nieprzypisany)'),
                datasets: [{ label: 'Tickety', data: data.map((r) => Number(r.total)) }],
            },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } },
        });
    } catch (e) { console.error('by_agent', e); }

    try {
        const { data } = await api('by_submitter', f);
        drawChart('chart-submitter', {
            type: 'bar',
            data: {
                labels: data.map((r) => r.submitter || '(nieznany)'),
                datasets: [{ label: 'Zgłoszenia', data: data.map((r) => Number(r.total)) }],
            },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } },
        });
    } catch (e) { console.error('by_submitter', e); }
}

// --- Start -----------------------------------------------------------------

async function initPriorities() {
    const sel = $('#f-priority');
    try {
        const { data } = await api('priorities');
        if (!data.length) {
            sel.innerHTML = '<option value="">(brak priorytetów)</option>';
            return;
        }
        sel.innerHTML = data.map((p) =>
            `<option value="${esc(p.priority_id)}">${esc(p.priority_desc)} (${esc(p.priority)})</option>`
        ).join('');
        // Domyślnie wybierz priorytet "high", jeśli jest.
        const high = data.find((p) => String(p.priority).toLowerCase() === 'high');
        if (high) sel.value = String(high.priority_id);
    } catch (e) {
        sel.innerHTML = `<option value="">Błąd: ${esc(e.message)}</option>`;
    }
}

async function applyAll() {
    await Promise.all([loadMain(), loadCharts()]);
}

document.addEventListener('DOMContentLoaded', async () => {
    // Domyślny zakres: ostatnie 90 dni.
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - 90);
    $('#f-to').value = to.toISOString().slice(0, 10);
    $('#f-from').value = from.toISOString().slice(0, 10);

    $('#f-apply').addEventListener('click', applyAll);
    $('#f-csv').addEventListener('click', exportCsv);

    await initPriorities();
    await applyAll();
});
