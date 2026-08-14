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
let CURRENT_DIM = 'staff';    // aktywny wymiar w sekcji „Mapa cieplna"
let CURRENT_METRIC = 'avg_first_response'; // aktywna miara czasu w tej samej sekcji

// --- Zakładki --------------------------------------------------------------
const TAB_TITLES = { overview: 'Przegląd', priorities: 'Priorytety', people: 'Pracownicy', ratings: 'Oceny' };

function showTab(tab) {
    if (!TAB_TITLES[tab]) tab = 'overview';
    document.querySelectorAll('.tab-panel').forEach((p) => { p.hidden = p.dataset.tab !== tab; });
    document.querySelectorAll('.nav-item[data-tab]').forEach((n) => n.classList.toggle('active', n.dataset.tab === tab));
    const t = document.getElementById('page-title');
    if (t) t.textContent = TAB_TITLES[tab];
    // wykresy narysowane w ukrytej zakładce mają zerowy rozmiar — popraw po odsłonięciu
    requestAnimationFrame(() => { Object.values(charts).forEach((c) => { try { c.resize(); } catch (e) {} }); });
}

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

// --- Komponent: rozwijana lista z checkboxami (zamiast <select multiple>) --

function createMultiSelect(container, items, opts) {
    opts = opts || {};
    const getId    = opts.getId    || ((x) => x.id);
    const getLabel = opts.getLabel || ((x) => x.name);
    let selected = new Set((opts.selected || items.map(getId)).map(String));

    container.classList.add('msel');
    container.innerHTML = `
        <button type="button" class="msel-toggle">
            <span class="msel-label"></span>
            <svg class="msel-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="msel-panel" hidden>
            <div class="msel-actions">
                <button type="button" data-act="all">Zaznacz wszystkie</button>
                <button type="button" data-act="none">Wyczyść</button>
            </div>
            <div class="msel-options"></div>
        </div>`;

    const toggle = container.querySelector('.msel-toggle');
    const label  = container.querySelector('.msel-label');
    const panel  = container.querySelector('.msel-panel');
    const box    = container.querySelector('.msel-options');

    box.innerHTML = items.map((it) => {
        const id = esc(String(getId(it)));
        const checked = selected.has(String(getId(it))) ? 'checked' : '';
        return `<label class="msel-option"><input type="checkbox" value="${id}" ${checked}><span>${esc(getLabel(it))}</span></label>`;
    }).join('');

    function updateLabel() {
        if (!items.length) label.textContent = 'Brak pozycji';
        else if (selected.size === 0) label.textContent = 'Brak wybranych';
        else if (selected.size === items.length) label.textContent = 'Wszystkie';
        else if (selected.size === 1) {
            const only = Array.from(selected)[0];
            const it = items.find((x) => String(getId(x)) === only);
            label.textContent = it ? getLabel(it) : '1 wybrany';
        } else label.textContent = `Wybrano ${selected.size} z ${items.length}`;
    }
    updateLabel();

    function closePanel() { panel.hidden = true; container.classList.remove('open'); }

    toggle.addEventListener('click', (ev) => {
        ev.stopPropagation();
        const willOpen = panel.hidden;
        document.querySelectorAll('.msel-panel').forEach((p) => { p.hidden = true; });
        document.querySelectorAll('.msel.open').forEach((m) => m.classList.remove('open'));
        panel.hidden = !willOpen;
        container.classList.toggle('open', willOpen);
    });
    document.addEventListener('click', (ev) => { if (!container.contains(ev.target)) closePanel(); });

    box.addEventListener('change', (ev) => {
        const cb = ev.target.closest('input[type=checkbox]');
        if (!cb) return;
        if (cb.checked) selected.add(cb.value); else selected.delete(cb.value);
        updateLabel();
        if (opts.onChange) opts.onChange(Array.from(selected));
    });

    panel.querySelector('[data-act="all"]').addEventListener('click', () => {
        selected = new Set(items.map((it) => String(getId(it))));
        box.querySelectorAll('input[type=checkbox]').forEach((cb) => { cb.checked = true; });
        updateLabel();
        if (opts.onChange) opts.onChange(Array.from(selected));
    });
    panel.querySelector('[data-act="none"]').addEventListener('click', () => {
        selected = new Set();
        box.querySelectorAll('input[type=checkbox]').forEach((cb) => { cb.checked = false; });
        updateLabel();
        if (opts.onChange) opts.onChange(Array.from(selected));
    });

    return {
        getSelected: () => Array.from(selected),
        setSelected: (ids) => {
            selected = new Set(ids.map(String));
            box.querySelectorAll('input[type=checkbox]').forEach((cb) => { cb.checked = selected.has(cb.value); });
            updateLabel();
        },
    };
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

// Podkreślony (wyróżniony) czas trwania — jako pigułka.
function durBadge(seconds, kind) {
    if (seconds === null || seconds === undefined) return '<span class="muted">—</span>';
    return `<span class="dur dur-${kind}">${esc(fmtDuration(seconds))}</span>`;
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
        tbody.innerHTML = '<tr><td colspan="9" class="muted">Wybierz priorytet.</td></tr>';
        return;
    }
    tbody.innerHTML = '<tr><td colspan="9" class="muted">Ładowanie…</td></tr>';
    try {
        const { data } = await api('high_priority_closed',
            Object.assign({ priority_id: priorityId }, currentFilters()));
        lastMainRows = data;

        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="muted">Brak zgłoszeń dla wybranych kryteriów.</td></tr>';
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
                <td>${durBadge(r.first_response_seconds, 'fr')}</td>
                <td>${esc(fmtDateTime(r.closed_at))}</td>
                <td>${durBadge(r.resolution_seconds, 'res')}</td>
            </tr>`).join('');
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        tbody.innerHTML = `<tr><td colspan="9" class="error">Błąd: ${esc(e.message)}</td></tr>`;
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

let DEPARTMENTS = [];
let DEPT_MSEL = null;      // wybór działów w „Wyniki wg wymiaru"
let PR_DEPT_MSEL = null;   // wybór działów w „Ranking priorytetowy"

async function loadDepartmentsFilter() {
    const bdEl = $('#bd-dept-msel');
    const prEl = $('#pr-dept-msel');
    try {
        const { data } = await api('departments');
        DEPARTMENTS = data || [];
        const def = (window.OSTA && window.OSTA.defaultDept) || '';
        const preferred = DEPARTMENTS.filter((d) => d.name === def).map((d) => String(d.id));
        const bdDefault = preferred.length ? preferred : DEPARTMENTS.map((d) => String(d.id));

        if (bdEl) {
            DEPT_MSEL = createMultiSelect(bdEl, DEPARTMENTS, {
                getId: (d) => d.id, getLabel: (d) => d.name,
                selected: bdDefault,
                onChange: () => loadBreakdown(),
            });
        }
        if (prEl) {
            PR_DEPT_MSEL = createMultiSelect(prEl, DEPARTMENTS, {
                getId: (d) => d.id, getLabel: (d) => d.name,
                selected: DEPARTMENTS.map((d) => String(d.id)), // ranking: domyślnie wszystkie działy
                onChange: () => loadPriorityRanking(),
            });
        }
    } catch (e) {
        console.error('departments', e);
    }
}

function selectedDeptIds() {
    return DEPT_MSEL ? DEPT_MSEL.getSelected() : [];
}

// Kolor mapy cieplnej: 0 = najszybciej w kolumnie (zielony) … 1 = najwolniej (czerwony).
function heatColor(pct) {
    pct = Math.max(0, Math.min(1, isFinite(pct) ? pct : 0.5));
    const stops = [
        { p: 0,   c: [201, 236, 215] }, // zielony — szybciej
        { p: 0.5, c: [255, 233, 179] }, // bursztynowy — przeciętnie
        { p: 1,   c: [251, 210, 210] }, // czerwony — wolniej
    ];
    let lo = stops[0], hi = stops[1];
    for (let i = 0; i < stops.length - 1; i++) {
        if (pct >= stops[i].p && pct <= stops[i + 1].p) { lo = stops[i]; hi = stops[i + 1]; break; }
    }
    const t = (pct - lo.p) / ((hi.p - lo.p) || 1);
    const c = [0, 1, 2].map((i) => Math.round(lo.c[i] + (hi.c[i] - lo.c[i]) * t));
    return `rgb(${c[0]},${c[1]},${c[2]})`;
}

// Pozycja wartości względem posortowanej kolumny (0..1), z uśrednieniem remisów.
function percentileForValue(v, sortedVals) {
    if (v === null || v === undefined || !sortedVals.length) return null;
    if (sortedVals.length === 1) return 0.5;
    let lo = 0;
    while (lo < sortedVals.length && sortedVals[lo] < v) lo++;
    let hi = lo;
    while (hi < sortedVals.length && sortedVals[hi] === v) hi++;
    const rank = (lo + hi - 1) / 2;
    return rank / (sortedVals.length - 1);
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
    const entList = Object.values(ents).sort((a, b) => a.name.localeCompare(b.name, 'pl'));

    const fmtCell = (v) => {
        if (v === null || v === undefined) return '<span class="muted">—</span>';
        return isTime ? fmtHours(v) : Number(v).toLocaleString('pl-PL');
    };

    // Mapa cieplna tylko dla średnich czasowych — suma/liczba nie są uczciwym
    // porównaniem „kto najgorszy" (więcej ticketów = większa suma, to nie wina agenta).
    const heatOn = isTime && (CURRENT_METRIC === 'avg_first_response' || CURRENT_METRIC === 'avg_resolution');
    const colStats = {};
    if (heatOn) {
        cols.forEach((p) => {
            colStats[p.priority_id] = entList
                .map((e) => e.byPri[p.priority_id])
                .filter((v) => v !== null && v !== undefined)
                .map(Number)
                .sort((a, b) => a - b);
        });
    }

    tbody.innerHTML = entList.map((e) => `<tr>
            <td>${esc(e.name)}</td>
            ${cols.map((p) => {
                const v = e.byPri[p.priority_id];
                let style = '';
                if (heatOn && v !== null && v !== undefined) {
                    const pct = percentileForValue(Number(v), colStats[p.priority_id]);
                    if (pct !== null) style = ` style="background:${heatColor(pct)}"`;
                }
                return `<td${style}>${fmtCell(v)}</td>`;
            }).join('')}
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
        metric: CURRENT_METRIC,
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

// --- Ranking priorytetowy: kto ma najgorsze czasy --------------------------
// Liczy się średni czas do 1. odpowiedzi ORAZ do zamknięcia, plus wskazanie
// KONKRETNEGO ticketa, który najbardziej zawyżył średnią danego agenta —
// żeby dało się od razu odróżnić pojedynczy odstający przypadek od problemu
// systemowego.

let PR_PRIORITY_MSEL = null;
let PR_TICKETS = [];

function buildPriorityMultiSelect() {
    const el = $('#pr-priority-msel');
    if (!el || !PRIORITIES.length) return;

    const highRegex = /wysok|high|krytycz|pilne|emergency|urgent/i;
    let defaultIds = PRIORITIES
        .filter((p) => highRegex.test(p.priority_desc || '') || highRegex.test(p.priority || ''))
        .map((p) => String(p.priority_id));
    if (!defaultIds.length) {
        // fallback: górna połowa wg wagi (urgency), gdy nazwy nie dają się rozpoznać
        const sorted = [...PRIORITIES].sort((a, b) => Number(b.priority_urgency) - Number(a.priority_urgency));
        defaultIds = sorted.slice(0, Math.max(1, Math.ceil(sorted.length / 2))).map((p) => String(p.priority_id));
    }

    PR_PRIORITY_MSEL = createMultiSelect(el, PRIORITIES, {
        getId: (p) => p.priority_id, getLabel: (p) => p.priority_desc,
        selected: defaultIds,
        onChange: () => loadPriorityRanking(),
    });
}

async function loadPriorityRanking() {
    const tbody = $('#pr-ranking-table tbody');
    if (!tbody) return;
    $('#pr-detail').hidden = true;

    const priorityIds = PR_PRIORITY_MSEL ? PR_PRIORITY_MSEL.getSelected() : [];
    if (!priorityIds.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="muted">Wybierz co najmniej jeden priorytet.</td></tr>';
        $('#pr-note').textContent = '';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="6" class="muted">Ładowanie…</td></tr>';
    const params = Object.assign({
        priority_ids: priorityIds,
        dept_ids: PR_DEPT_MSEL ? PR_DEPT_MSEL.getSelected() : [],
        active_only: $('#pr-active').checked ? '1' : '0',
    }, currentFilters());

    try {
        const { data } = await api('priority_ranking', params);
        PR_TICKETS = data.tickets || [];
        const summary = data.summary || [];

        if (!summary.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="muted">Brak zamkniętych ticketów dla wybranych filtrów.</td></tr>';
            $('#pr-note').textContent = '';
            return;
        }

        const totalTickets = summary.reduce((a, s) => a + Number(s.count || 0), 0);
        $('#pr-note').textContent = `${summary.length} pracowników · ${totalTickets} priorytetowych ticketów w wybranym zakresie.`;

        // Próg ostrzegawczy: wyraźnie powyżej mediany śr. czasu rozwiązania.
        const resValues = summary.map((s) => s.avg_resolution_seconds).filter((v) => v != null).sort((a, b) => a - b);
        const median = resValues.length ? resValues[Math.floor(resValues.length / 2)] : 0;

        tbody.innerHTML = summary.map((s, idx) => {
            const worst = idx === 0 && s.avg_resolution_seconds != null;
            const warn  = !worst && median > 0 && s.avg_resolution_seconds != null && s.avg_resolution_seconds > median * 1.3;
            const rowClass = worst ? 'row-worst' : (warn ? 'row-warn' : '');
            const fr = s.max_first_response_ticket;
            const rs = s.max_resolution_ticket;
            return `<tr class="${rowClass}" data-staff="${esc(s.staff_id)}" data-name="${esc(s.agent)}">
                <td>
                    ${worst ? '<span class="badge-worst" title="Najdłuższy średni czas rozwiązania">🔻 najgorszy</span> ' : ''}
                    <strong>${esc(s.agent)}</strong>
                    ${!Number(s.isactive) ? '<span class="badge off" style="margin-left:6px">nieaktywny</span>' : ''}
                </td>
                <td>${Number(s.count).toLocaleString('pl-PL')}</td>
                <td>${durBadge(s.avg_first_response_seconds, 'fr')}</td>
                <td>${fr ? `<button type="button" class="linklike-cell" data-jump="${esc(fr.number)}">#${esc(fr.number)} · ${esc(fmtDuration(fr.seconds))}</button>` : '<span class="muted">—</span>'}</td>
                <td>${durBadge(s.avg_resolution_seconds, 'res')}</td>
                <td>${rs ? `<button type="button" class="linklike-cell" data-jump="${esc(rs.number)}">#${esc(rs.number)} · ${esc(fmtDuration(rs.seconds))}</button>` : '<span class="muted">—</span>'}</td>
            </tr>`;
        }).join('');

        tbody.querySelectorAll('tr[data-staff]').forEach((tr) => {
            tr.addEventListener('click', (ev) => {
                if (ev.target.closest('.linklike-cell')) return; // ma własną obsługę niżej
                openAgentDrilldown(tr.dataset.staff, tr.dataset.name);
            });
        });
        tbody.querySelectorAll('.linklike-cell').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                const tr = ev.target.closest('tr');
                openAgentDrilldown(tr.dataset.staff, tr.dataset.name, btn.dataset.jump);
            });
        });
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        tbody.innerHTML = `<tr><td colspan="6" class="error">Błąd: ${esc(e.message)}</td></tr>`;
    }
}

// Rozwija pełną listę priorytetowych ticketów danego agenta (do „poczytania"),
// posortowaną od najdłuższego czasu rozwiązania — z podświetleniem tego,
// który najbardziej zawyżył średnią.
function openAgentDrilldown(staffId, agentName, jumpToNumber) {
    const box = $('#pr-detail');
    const tbody = $('#pr-detail-table tbody');
    $('#pr-detail-title').textContent = `Tickety pracownika: ${agentName}`;

    const rows = PR_TICKETS
        .filter((t) => String(t.staff_id) === String(staffId))
        .slice()
        .sort((a, b) => (Number(b.resolution_seconds) || 0) - (Number(a.resolution_seconds) || 0));

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="muted">Brak ticketów.</td></tr>';
    } else {
        const worstRes = rows[0] ? Number(rows[0].resolution_seconds) : null;
        tbody.innerHTML = rows.map((r) => {
            const isWorst = worstRes != null && Number(r.resolution_seconds) === worstRes;
            return `<tr class="${isWorst ? 'row-worst' : ''}" id="pr-ticket-${esc(r.number)}">
                <td>${esc(r.number)}</td>
                <td>${esc(r.subject || '')}</td>
                <td>${esc(r.submitter || '')}</td>
                <td>${esc(r.priority_name || '')}</td>
                <td>${esc(fmtDateTime(r.opened_at))}</td>
                <td>${durBadge(r.first_response_seconds, 'fr')}</td>
                <td>${esc(fmtDateTime(r.closed_at))}</td>
                <td>${durBadge(r.resolution_seconds, 'res')}</td>
            </tr>`;
        }).join('');
    }

    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (jumpToNumber) {
        const el = document.getElementById('pr-ticket-' + jumpToNumber);
        if (el) setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 250);
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

    // reset szczegółów przy każdym odświeżeniu
    $('#rating-detail').hidden = true;

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
                backgroundColor: colors, borderRadius: 6, maxBarThickness: 70 }],
        },
        options: {
            responsive: true,
            onHover: (ev, els) => { ev.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
            onClick: (ev, els) => { if (els.length) loadRatingDetail(els[0].index + 1); },
            plugins: { legend: { display: false },
                tooltip: { callbacks: { footer: () => 'Kliknij, aby przeczytać tickety' } } },
        },
    });
}

async function loadRatingDetail(rating) {
    const box = $('#rating-detail');
    const tbody = $('#rating-detail-table tbody');
    const stars = '★'.repeat(rating) + '☆'.repeat(5 - rating);
    $('#rating-detail-title').innerHTML = `Tickety z oceną <span style="color:#f0a500">${stars}</span> (${rating}/5)`;
    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    tbody.innerHTML = '<tr><td colspan="8" class="muted">Ładowanie…</td></tr>';
    try {
        const { data } = await api('ratings_detail', Object.assign({ rating }, currentFilters()));
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="muted">Brak ticketów z tą oceną w wybranym okresie.</td></tr>';
            return;
        }
        tbody.innerHTML = data.map((r) => `
            <tr>
                <td>${esc(r.number)}</td>
                <td>${esc(r.subject || '')}</td>
                <td>${esc(r.submitter || '')}</td>
                <td>${esc(r.agent || '')}</td>
                <td>${esc(fmtDateTime(r.opened_at))}</td>
                <td>${durBadge(r.first_response_seconds, 'fr')}</td>
                <td>${esc(fmtDateTime(r.closed_at))}</td>
                <td>${durBadge(r.resolution_seconds, 'res')}</td>
            </tr>`).join('');
    } catch (e) {
        if (MODE === 'prompt' && e.status >= 400) return openCredsModal(e.message);
        tbody.innerHTML = `<tr><td colspan="8" class="error">Błąd: ${esc(e.message)}</td></tr>`;
    }
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
        loadDepartments(), loadBreakdown(), loadRatings(), loadPriorityRanking(),
    ]);
}

// Wczytuje dane słownikowe (priorytety, działy) i odświeża cały pulpit.
async function boot() {
    await initPriorities();
    await loadDepartmentsFilter();
    buildPriorityMultiSelect();
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

    // Zakładki nawigacji
    document.querySelectorAll('.nav-item[data-tab]').forEach((n) => {
        n.addEventListener('click', (ev) => {
            ev.preventDefault();
            const tab = n.dataset.tab;
            if (history.replaceState) history.replaceState(null, '', '#' + tab);
            showTab(tab);
        });
    });
    const initialTab = (location.hash || '').replace('#', '');
    showTab(TAB_TITLES[initialTab] ? initialTab : 'overview');

    // Sekcja „Wyniki wg wymiaru"
    $('#dim-tabs').addEventListener('click', (ev) => {
        const btn = ev.target.closest('.dim-tab');
        if (!btn) return;
        document.querySelectorAll('.dim-tab').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        CURRENT_DIM = btn.dataset.dim;
        loadBreakdown();
    });
    $('#bd-metric-tabs').addEventListener('click', (ev) => {
        const btn = ev.target.closest('.metric-tab');
        if (!btn) return;
        document.querySelectorAll('#bd-metric-tabs .metric-tab').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        CURRENT_METRIC = btn.dataset.metric;
        loadBreakdown();
    });
    $('#bd-active').addEventListener('change', () => {
        $('#bd-active-label').textContent = $('#bd-active').checked ? 'tylko włączone' : 'wszystkie';
        loadBreakdown();
    });

    // Ranking priorytetowy (zakładka Priorytety)
    $('#pr-active').addEventListener('change', () => {
        $('#pr-active-label').textContent = $('#pr-active').checked ? 'tylko włączone' : 'wszystkie';
        loadPriorityRanking();
    });
    $('#pr-detail-close').addEventListener('click', () => { $('#pr-detail').hidden = true; });
    $('#jump-to-rollout').addEventListener('click', () => {
        $('#f-from').value = '2026-04-29';
        applyAll();
    });

    if (MODE === 'prompt') {
        $('#creds-form').addEventListener('submit', handleCredsSubmit);
        $('#creds-forget').addEventListener('click', () => { forgetCreds(); openCredsModal(); });
        if (!getCreds()) { openCredsModal(); return; } // czekaj na dane
    }

    await boot();
});
