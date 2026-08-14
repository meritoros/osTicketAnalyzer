/* Front dashboardu: pobiera dane z api.php (JSON) i rysuje tabelę oraz wykresy.
   Przeglądarka NIE łączy się z bazą — dostaje tylko policzone wyniki.

   Tryb 'prompt': dane do bazy wpisujesz w okienku, trzymane są tylko w sessionStorage
   i wysyłane do backendu przy każdym zapytaniu (przez HTTPS). Nic nie jest zapisywane. */
'use strict';

const $ = (sel) => document.querySelector(sel);
const MODE = (window.OSTA && window.OSTA.mode) || 'config';
const CREDS_KEY = 'osta_db_creds';

const charts = {}; // uchwyty Chart.js

// Paleta kategorialna (zwalidowana na ciemnym tle: zieleń→pomarańcz→niebieski→magenta→fiolet→czerwień)
const PALETTE = ['#33A667', '#d95926', '#3987e5', '#d55181', '#9085e9', '#e66767'];
const BRAND = '#2f9c63';       // seria „zieleń" na wykresach
const BLUE  = '#3b82f6';
const INK_MUTED = '#6b7280';   // tekst osi/legend (jasny motyw)
const GRID = '#e6e9f0';        // siatka (jasny motyw)
const PANEL = '#ffffff';

// Gradient słupka (poziomy/pionowy) w barwie brandu — jak we wzorcu.
function greenGradient(horizontal) {
    return (context) => {
        const { chart } = context;
        const { ctx, chartArea } = chart;
        if (!chartArea) return BRAND;
        const g = horizontal
            ? ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            : ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
        g.addColorStop(0, '#2b8f5a');
        g.addColorStop(1, '#6fd39b');
        return g;
    };
}

let PRIORITIES = [];          // pełna lista priorytetów (kolumny w przekroju)
let CURRENT_DIM = 'staff';    // aktywny wymiar w sekcji „Wyniki wg wymiaru"

// Kolory wg wagi priorytetu (spójne, czytelne). Fallback, gdy brak koloru z osTicketa.
function priorityColorByName(name) {
    const n = String(name || '').toLowerCase();
    if (/(emergency|krytyc|awari|krityc)/.test(n)) return '#e34948'; // krytyczny → czerwony
    if (/(high|wysok)/.test(n))                     return '#eda100'; // wysoki → bursztyn
    if (/(low|nisk)/.test(n))                       return BRAND;      // niski → zielony
    if (/(normal|średn|sredn|zwyk)/.test(n))        return BLUE;       // normalny → niebieski
    return INK_MUTED;
}

// Kolor priorytetu wg wagi (czytelny). Kolory z osTicketa są zbyt pastelowe,
// więc używamy własnej skali; kolor z bazy tylko gdy nazwa nierozpoznana.
function priorityColor(row) {
    const byName = priorityColorByName(row.priority_name);
    if (byName !== INK_MUTED) return byName;
    const c = String(row.priority_color || '').trim();
    if (/^#?[0-9a-fA-F]{6}$/.test(c)) return c.startsWith('#') ? c : '#' + c;
    return byName;
}

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
                    { label: 'Wszystkie', data: data.map((r) => Number(r.total)),
                      borderColor: BRAND, backgroundColor: BRAND, tension: 0.25 },
                    { label: 'Zamknięte', data: data.map((r) => Number(r.closed)),
                      borderColor: BLUE, backgroundColor: BLUE, tension: 0.25 },
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
                datasets: [{
                    data: data.map((r) => Number(r.total)),
                    backgroundColor: data.map(priorityColor),
                    borderColor: PANEL, borderWidth: 2,
                }],
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
                datasets: [{ label: 'Tickety', data: data.map((r) => Number(r.total)),
                    backgroundColor: greenGradient(true), borderRadius: 5, maxBarThickness: 22 }],
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
                datasets: [{ label: 'Zgłoszenia', data: data.map((r) => Number(r.total)),
                    backgroundColor: BLUE, borderRadius: 5, maxBarThickness: 22 }],
            },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } },
        });
    } catch (e) { console.error('by_submitter', e); }
}

// --- Agenci wg działów -----------------------------------------------------

async function loadDepartments() {
    const container = $('#dept-container');
    try {
        const { data } = await api('staff_by_department');
        if (!data.length) {
            container.innerHTML = '<p class="muted">Brak agentów.</p>';
            $('#dept-summary').textContent = '';
            return;
        }
        const groups = {};
        data.forEach((r) => {
            const key = r.department || '(bez działu)';
            (groups[key] = groups[key] || []).push(r);
        });

        let totalActive = 0, totalInactive = 0;
        const html = Object.keys(groups).sort((a, b) => a.localeCompare(b, 'pl')).map((dep) => {
            const list = groups[dep];
            const active = list.filter((a) => Number(a.isactive) === 1).length;
            const inactive = list.length - active;
            totalActive += active; totalInactive += inactive;

            const chips = list.map((a) => {
                const on = Number(a.isactive) === 1;
                const name = (a.agent && a.agent.trim()) || a.username || '(bez nazwy)';
                return `<span class="agent-chip ${on ? '' : 'inactive'}" title="${esc(a.email || '')}">
                    <i class="dot ${on ? 'on' : 'off'}"></i>${esc(name)}
                    <span class="badge ${on ? 'on' : 'off'}">${on ? 'aktywny' : 'nieaktywny'}</span>
                </span>`;
            }).join('');

            return `<div class="dept">
                <div class="dept-head">
                    <span class="dept-name">${esc(dep)}</span>
                    <span class="dept-meta">${list.length} agentów · ${active} aktywnych · ${inactive} nieaktywnych</span>
                </div>
                <div class="agent-list">${chips}</div>
            </div>`;
        }).join('');

        container.innerHTML = html;
        $('#dept-summary').textContent =
            `Razem: ${data.length} agentów · ${totalActive} aktywnych · ${totalInactive} nieaktywnych`;
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        container.innerHTML = `<p class="error">Błąd: ${esc(e.message)}</p>`;
    }
}

// --- Przegląd (górne KPI) --------------------------------------------------

async function loadOverview() {
    try {
        const { data } = await api('overview', currentFilters());
        const num = (v) => (v != null ? Number(v).toLocaleString('pl-PL') : '0');
        $('#ov-open').textContent   = num(data.open_now);
        $('#ov-closed').textContent = num(data.closed_in_range);
        $('#ov-fr').textContent  = fmtDuration(data.avg_first_response_seconds != null ? Math.round(data.avg_first_response_seconds) : null);
        $('#ov-res').textContent = fmtDuration(data.avg_resolution_seconds != null ? Math.round(data.avg_resolution_seconds) : null);
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        console.error('overview', e);
    }
}

// --- Analityka zamkniętych ticketów ----------------------------------------

async function loadClosedAnalytics() {
    const f = currentFilters();
    let res;
    try {
        res = (await api('closed_analytics', f)).data;
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        console.error('closed_analytics', e);
        return;
    }
    const summary = res.summary || {};
    const byPriority = res.by_priority || [];
    const byDept = res.by_department || [];
    const overTime = res.over_time || [];

    const num = (v) => (v != null ? Number(v).toLocaleString('pl-PL') : '0');
    $('#kpi-closed').textContent    = num(summary.total_closed);
    $('#kpi-resolution').textContent = fmtDuration(summary.avg_resolution_seconds != null ? Math.round(summary.avg_resolution_seconds) : null);
    $('#kpi-firstresp').textContent = fmtDuration(summary.avg_first_response_seconds != null ? Math.round(summary.avg_first_response_seconds) : null);
    $('#kpi-withresp').textContent  = num(summary.with_response);

    drawChart('chart-closed-priority', {
        type: 'doughnut',
        data: {
            labels: byPriority.map((r) => r.priority_name || '(brak)'),
            datasets: [{
                data: byPriority.map((r) => Number(r.total)),
                backgroundColor: byPriority.map(priorityColor),
                borderColor: '#1e293b', borderWidth: 2,
            }],
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
    });

    drawChart('chart-closed-restime', {
        type: 'bar',
        data: {
            labels: byPriority.map((r) => r.priority_name || '(brak)'),
            datasets: [{
                label: 'Śr. czas rozwiązania (godz.)',
                data: byPriority.map((r) => (r.avg_resolution_seconds != null ? +(r.avg_resolution_seconds / 3600).toFixed(1) : 0)),
                backgroundColor: byPriority.map(priorityColor), borderRadius: 5, maxBarThickness: 46,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (c) => ' ' + fmtDuration(byPriority[c.dataIndex].avg_resolution_seconds || 0) } },
            },
            scales: { y: { title: { display: true, text: 'godziny' } } },
        },
    });

    // Śr. czas 1. odpowiedzi wg priorytetu
    drawChart('chart-closed-frtime', {
        type: 'bar',
        data: {
            labels: byPriority.map((r) => r.priority_name || '(brak)'),
            datasets: [{
                label: 'Śr. czas 1. odpowiedzi (godz.)',
                data: byPriority.map((r) => (r.avg_first_response_seconds != null ? +(r.avg_first_response_seconds / 3600).toFixed(1) : 0)),
                backgroundColor: byPriority.map(priorityColor), borderRadius: 5, maxBarThickness: 46,
            }],
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (c) => ' ' + fmtDuration(byPriority[c.dataIndex].avg_first_response_seconds || 0) } },
            },
            scales: { y: { title: { display: true, text: 'godziny' } } },
        },
    });

    drawChart('chart-closed-time', {
        type: 'line',
        data: {
            labels: overTime.map((r) => r.day),
            datasets: [{
                label: 'Zamknięte', data: overTime.map((r) => Number(r.closed)),
                borderColor: BRAND, backgroundColor: BRAND, tension: 0.25, fill: false,
            }],
        },
        options: { responsive: true, plugins: { legend: { display: false } } },
    });

    const tb = $('#closed-dept-table tbody');
    tb.innerHTML = byDept.length
        ? byDept.map((r) => `<tr>
                <td>${esc(r.department)}</td>
                <td>${num(r.total)}</td>
                <td>${esc(fmtDuration(r.avg_resolution_seconds != null ? Math.round(r.avg_resolution_seconds) : null))}</td>
            </tr>`).join('')
        : '<tr><td colspan="3" class="muted">Brak danych.</td></tr>';
}

// --- Wyniki wg wymiaru (pivot) ---------------------------------------------

// Kompaktowy czas do gęstej tabeli: „12,5 h" / „45 min" / „30 s".
function fmtHours(seconds) {
    if (seconds === null || seconds === undefined) return '—';
    seconds = Number(seconds);
    if (!isFinite(seconds) || seconds < 0) return '—';
    if (seconds >= 3600) return (seconds / 3600).toLocaleString('pl-PL', { maximumFractionDigits: 1 }) + ' h';
    if (seconds >= 60)   return Math.round(seconds / 60) + ' min';
    return Math.round(seconds) + ' s';
}

async function loadDepartmentsFilter() {
    const sel = $('#bd-dept');
    if (!sel) return;
    try {
        const { data } = await api('departments');
        const def = (window.OSTA && window.OSTA.defaultDept) || '';
        sel.innerHTML = data.map((d) =>
            `<option value="${esc(d.id)}"${d.name === def ? ' selected' : ''}>${esc(d.name)}</option>`
        ).join('');
    } catch (e) {
        sel.innerHTML = '';
        console.error('departments', e);
    }
}

function selectedDeptIds() {
    const sel = $('#bd-dept');
    if (!sel) return [];
    return Array.from(sel.selectedOptions).map((o) => o.value);
}

function buildBreakdownTable(rows, isTime) {
    const cols = [...PRIORITIES].sort((a, b) => Number(b.priority_urgency) - Number(a.priority_urgency));
    const thead = $('#bd-table thead');
    const tbody = $('#bd-table tbody');

    thead.innerHTML = '<tr><th>' + (CURRENT_DIM === 'staff' ? 'Pracownik'
        : CURRENT_DIM === 'user' ? 'Użytkownik'
        : CURRENT_DIM === 'team' ? 'Zespół' : 'Oddział') + '</th>'
        + cols.map((p) => `<th><span class="pri-h"><i class="dot" style="background:${priorityColor(p)}"></i>${esc(p.priority_desc)}</span></th>`).join('')
        + '<th>Ticketów</th></tr>';

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${cols.length + 2}" class="muted">Brak danych dla wybranych filtrów.</td></tr>`;
        return;
    }

    const ents = {};
    rows.forEach((r) => {
        const id = r.entity_id;
        if (!ents[id]) ents[id] = { name: r.entity_name || '(brak)', byPri: {}, total: 0 };
        ents[id].byPri[r.priority_id] = r.value;
        ents[id].total += Number(r.cnt || 0);
    });

    const fmtCell = (v) => {
        if (v === null || v === undefined) return '<span class="muted">—</span>';
        return isTime ? fmtHours(v) : Number(v).toLocaleString('pl-PL');
    };

    tbody.innerHTML = Object.values(ents)
        .sort((a, b) => a.name.localeCompare(b.name, 'pl'))
        .map((e) => `<tr>
            <td>${esc(e.name)}</td>
            ${cols.map((p) => `<td>${fmtCell(e.byPri[p.priority_id])}</td>`).join('')}
            <td>${Number(e.total).toLocaleString('pl-PL')}</td>
        </tr>`).join('');
}

async function loadBreakdown() {
    const tbody = $('#bd-table tbody');
    tbody.innerHTML = '<tr><td class="muted">Ładowanie…</td></tr>';

    // pokaż/ukryj filtry działów+kont tylko dla pracowników
    const staffOnly = CURRENT_DIM === 'staff';
    document.querySelectorAll('.bd-staff-only').forEach((el) => { el.style.display = staffOnly ? '' : 'none'; });

    const params = Object.assign({
        dimension: CURRENT_DIM,
        metric: $('#bd-metric').value,
        active_only: $('#bd-active').checked ? '1' : '0',
    }, currentFilters());
    if (staffOnly) params.dept_ids = selectedDeptIds();

    try {
        const resp = await api('breakdown', params);
        const isTime = resp.meta ? !!resp.meta.is_time : true;
        buildBreakdownTable(resp.data || [], isTime);
        $('#bd-note').textContent = 'Dane dla ticketów zamkniętych w wybranym zakresie dat'
            + (staffOnly ? ' · przypisany agent.' : '.');
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        tbody.innerHTML = `<tr><td class="error">Błąd: ${esc(e.message)}</td></tr>`;
    }
}

// --- Oceny ticketów --------------------------------------------------------

async function loadRatings() {
    const note = $('#rating-note');
    let res;
    try {
        res = (await api('ratings', currentFilters())).data;
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        console.error('ratings', e);
        return;
    }
    if (!res || res.available === false) {
        $('#rating-kpis').hidden = true;
        note.innerHTML = 'Nie wykryto pola z oceną (gwiazdkami). Ustaw <code>rating.field</code> w config.php '
            + '— w diagnostyce zobaczysz listę pól formularza.';
        if (charts['chart-rating']) { charts['chart-rating'].destroy(); delete charts['chart-rating']; }
        return;
    }

    const s = res.summary || {};
    const total = Number(s.total_closed || 0);
    const rated = Number(s.rated || 0);
    $('#kpi-rating-avg').textContent   = s.avg_rating != null ? Number(s.avg_rating).toLocaleString('pl-PL', { maximumFractionDigits: 2 }) + ' ★' : '—';
    $('#kpi-rating-count').textContent = rated.toLocaleString('pl-PL');
    $('#kpi-rating-pct').textContent   = total ? Math.round((rated / total) * 100) + '%' : '—';
    $('#kpi-rating-total').textContent = total.toLocaleString('pl-PL');
    $('#rating-kpis').hidden = false;
    note.textContent = 'Ocena liczona z liczby gwiazdek (1–5). Nie wszystkie tickety są ocenione.';

    const dist = res.distribution || [];
    const byRating = {}; dist.forEach((d) => { byRating[Number(d.rating)] = Number(d.cnt); });
    const labels = [1, 2, 3, 4, 5];
    // od czerwieni (1) do zieleni (5)
    const colors = ['#e34948', '#eda100', '#f0c000', '#7fbf5a', BRAND];

    drawChart('chart-rating', {
        type: 'bar',
        data: {
            labels: labels.map((n) => n + ' ★'),
            datasets: [{ label: 'Liczba ocen', data: labels.map((n) => byRating[n] || 0),
                backgroundColor: colors, borderRadius: 4 }],
        },
        options: { responsive: true, plugins: { legend: { display: false } } },
    });
}

// --- Priorytety ------------------------------------------------------------

async function initPriorities() {
    const sel = $('#f-priority');
    try {
        const { data } = await api('priorities');
        PRIORITIES = data || [];
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
    await Promise.all([
        loadOverview(), loadMain(), loadCharts(), loadClosedAnalytics(),
        loadDepartments(), loadBreakdown(), loadRatings(),
    ]);
}

// Wczytuje dane słownikowe (priorytety, działy) i odświeża cały pulpit.
async function boot() {
    await Promise.all([initPriorities(), loadDepartmentsFilter()]);
    await applyAll();
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
        await boot();
    } catch (e) {
        forgetCreds();
        status.textContent = '';
        err.textContent = 'Nie udało się połączyć: ' + e.message;
        err.hidden = false;
    }
}

// --- Start -----------------------------------------------------------------

document.addEventListener('DOMContentLoaded', async () => {
    // Motyw wykresów pod ciemny interfejs.
    if (window.Chart) {
        Chart.defaults.color = INK_MUTED;
        Chart.defaults.borderColor = GRID;
        Chart.defaults.font.family = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
    }

    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - 90);
    $('#f-to').value = to.toISOString().slice(0, 10);
    $('#f-from').value = from.toISOString().slice(0, 10);

    $('#f-apply').addEventListener('click', applyAll);
    $('#f-csv').addEventListener('click', exportCsv);

    // Sekcja „Wyniki wg wymiaru"
    $('#dim-tabs').addEventListener('click', (ev) => {
        const btn = ev.target.closest('.dim-tab');
        if (!btn) return;
        document.querySelectorAll('.dim-tab').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        CURRENT_DIM = btn.dataset.dim;
        loadBreakdown();
    });
    $('#bd-metric').addEventListener('change', loadBreakdown);
    $('#bd-dept').addEventListener('change', loadBreakdown);
    $('#bd-active').addEventListener('change', () => {
        $('#bd-active-label').textContent = $('#bd-active').checked ? 'tylko włączone' : 'wszystkie';
        loadBreakdown();
    });

    if (MODE === 'prompt') {
        $('#creds-form').addEventListener('submit', handleCredsSubmit);
        $('#creds-forget').addEventListener('click', () => { forgetCreds(); openCredsModal(); });
        if (!getCreds()) { openCredsModal(); return; } // czekaj na dane
    }

    await boot();
});
