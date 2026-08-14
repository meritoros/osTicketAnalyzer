/* Front dashboardu: pobiera dane z api.php (JSON) i rysuje tabelę oraz wykresy.
   Przeglądarka NIE łączy się z bazą — dostaje tylko policzone wyniki.

   Tryb 'prompt': dane do bazy wpisujesz w okienku, trzymane są tylko w sessionStorage
   i wysyłane do backendu przy każdym zapytaniu (przez HTTPS). Nic nie jest zapisywane. */
'use strict';

const $ = (sel) => document.querySelector(sel);
const MODE = (window.OSTA && window.OSTA.mode) || 'config';
const CREDS_KEY = 'osta_db_creds';

const charts = {}; // uchwyty Chart.js

// --- Dane do bazy (tryb prompt) --------------------------------------------

function getCreds() {
    if (MODE !== 'prompt') return null;
    try { return JSON.parse(sessionStorage.getItem(CREDS_KEY) || 'null'); }
    catch (e) { return null; }
}
function setCreds(c) { sessionStorage.setItem(CREDS_KEY, JSON.stringify(c)); }
function forgetCreds() { sessionStorage.removeItem(CREDS_KEY); }

// --- Wywołania API ---------------------------------------------------------

async function api(report, params = {}) {
    const payload = Object.assign({ report }, params);
    if (MODE === 'prompt') {
        const creds = getCreds();
        if (!creds) throw new Error('Brak danych do bazy — wpisz je w okienku.');
        payload.db = creds;
    }
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
    });
    if (res.status === 401) { window.location.href = 'login.php'; return { data: [] }; }
    const json = await res.json().catch(() => ({ error: 'Nieprawidłowa odpowiedź serwera.' }));
    if (json.error) {
        const err = new Error(json.error);
        err.status = res.status;
        throw err;
    }
    return json;
}

// --- Pomocnicze ------------------------------------------------------------

function currentFilters() {
    return { from: $('#f-from').value, to: $('#f-to').value };
}

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

function fmtDateTime(v) { return v ? String(v) : '—'; }

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
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        tbody.innerHTML = `<tr><td colspan="8" class="error">Błąd: ${esc(e.message)}</td></tr>`;
    }
}

function exportCsv() {
    if (!lastMainRows.length) { alert('Brak danych do eksportu — najpierw pokaż raport.'); return; }
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

// --- Priorytety ------------------------------------------------------------

async function initPriorities() {
    const sel = $('#f-priority');
    try {
        const { data } = await api('priorities');
        if (!data.length) { sel.innerHTML = '<option value="">(brak priorytetów)</option>'; return; }
        sel.innerHTML = data.map((p) =>
            `<option value="${esc(p.priority_id)}">${esc(p.priority_desc)} (${esc(p.priority)})</option>`
        ).join('');
        const high = data.find((p) => String(p.priority).toLowerCase() === 'high');
        if (high) sel.value = String(high.priority_id);
    } catch (e) {
        sel.innerHTML = `<option value="">Błąd: ${esc(e.message)}</option>`;
    }
}

async function applyAll() {
    await Promise.all([loadMain(), loadCharts()]);
}

// --- Okienko z danymi do bazy (tryb prompt) --------------------------------

function openCredsModal(errorMsg) {
    const modal = $('#creds-modal');
    if (!modal) return;
    const err = $('#creds-error');
    if (errorMsg) { err.textContent = errorMsg; err.hidden = false; }
    else { err.hidden = true; }
    $('#creds-forget').hidden = !getCreds();
    modal.hidden = false;
    const nameEl = $('#c-name');
    if (nameEl) nameEl.focus();
}

function closeCredsModal() { const m = $('#creds-modal'); if (m) m.hidden = true; }

function readCredsForm() {
    return {
        host:   $('#c-host').value.trim() || 'localhost',
        name:   $('#c-name').value.trim(),
        user:   $('#c-user').value.trim(),
        pass:   $('#c-pass').value,
        prefix: $('#c-prefix').value.trim() || 'ost_',
    };
}

async function handleCredsSubmit(ev) {
    ev.preventDefault();
    const status = $('#creds-status');
    const err = $('#creds-error');
    err.hidden = true;
    status.textContent = 'Łączenie…';

    const creds = readCredsForm();
    setCreds(creds); // api() weźmie je z sessionStorage

    try {
        const { data } = await api('diag');
        const ver = data.version ? ` (osTicket ${data.version})` : '';
        status.textContent = 'Połączono ✔' + ver;
        // wstępnie wybierz prefiks, gdyby trzeba było poprawić
        if (data.guessed_prefix && data.guessed_prefix !== creds.prefix) {
            err.textContent = `Uwaga: wykryty prefiks to „${data.guessed_prefix}". Popraw pole i połącz ponownie.`;
            err.hidden = false;
            $('#c-prefix').value = data.guessed_prefix;
            return;
        }
        closeCredsModal();
        await initPriorities();
        await applyAll();
    } catch (e) {
        forgetCreds();
        status.textContent = '';
        err.textContent = 'Nie udało się połączyć: ' + e.message;
        err.hidden = false;
    }
}

// --- Start -----------------------------------------------------------------

document.addEventListener('DOMContentLoaded', async () => {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - 90);
    $('#f-to').value = to.toISOString().slice(0, 10);
    $('#f-from').value = from.toISOString().slice(0, 10);

    $('#f-apply').addEventListener('click', applyAll);
    $('#f-csv').addEventListener('click', exportCsv);

    if (MODE === 'prompt') {
        $('#creds-form').addEventListener('submit', handleCredsSubmit);
        $('#creds-forget').addEventListener('click', () => { forgetCreds(); openCredsModal(); });
        if (!getCreds()) { openCredsModal(); return; } // czekaj na dane
    }

    await initPriorities();
    await applyAll();
});
