<?php
require __DIR__ . '/lib/bootstrap.php';
auth_require_page();

$title = htmlspecialchars((string) cfg('app.title', 'osTicket — Statystyki'), ENT_QUOTES);
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="bg-anim" aria-hidden="true">
    <span class="blob blob1"></span>
    <span class="blob blob2"></span>
    <span class="blob blob3"></span>
</div>

<div class="app">

    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
            </span>
            <span class="brand-name">osTicket<b>Analyzer</b></span>
        </div>
        <nav class="side-nav">
            <a class="nav-item active" href="#overview" data-tab="overview">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/></svg>
                Przegląd
            </a>
            <a class="nav-item" href="#priorities" data-tab="priorities">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22V15"/></svg>
                Priorytety
            </a>
            <a class="nav-item" href="#people" data-tab="people">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Pracownicy
            </a>
            <a class="nav-item" href="#ratings" data-tab="ratings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                Oceny
            </a>
            <a class="nav-item" href="#quality" data-tab="quality">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                Kontrola
            </a>
            <a class="nav-item" href="diagnostics.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                Diagnostyka
            </a>
        </nav>
        <div class="side-bottom">
            <?php if (auth_enabled()): ?>
            <a class="nav-item" href="logout.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                Wyloguj
            </a>
            <?php endif; ?>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <span class="page-title" id="page-title">Przegląd</span>
            <span class="spacer"></span>
            <a href="https://pulpit.meritoros.pl" target="_blank" rel="noopener">
                <img src="https://pulpit.meritoros.pl/img/client/meritoros.png" alt="Meritoros" class="brand-logo">
            </a>
        </header>

        <main class="content">

            <!-- Okienko na dane do bazy (tryb testowy 'prompt') -->
            <div id="creds-modal" class="modal-overlay" hidden>
                <form class="modal-card" id="creds-form" autocomplete="off">
                    <h2>Dane do bazy (tryb testowy)</h2>
                    <p class="muted">Dane trafiają tylko do tej sesji przeglądarki i lecą do backendu przez HTTPS — <strong>nic nie jest zapisywane</strong> na serwerze ani w repo.</p>
                    <div class="field"><label>Host</label><input id="c-host" value="localhost"></div>
                    <div class="field"><label>Nazwa bazy</label><input id="c-name" placeholder="serwer42725_..." required></div>
                    <div class="field"><label>Użytkownik</label><input id="c-user" placeholder="serwer42725_pomoc" required></div>
                    <div class="field"><label>Hasło</label><input id="c-pass" type="password" required></div>
                    <div class="field"><label>Prefiks tabel</label><input id="c-prefix" value="ost_"></div>
                    <p class="error" id="creds-error" hidden></p>
                    <div class="modal-actions">
                        <button type="submit" id="creds-submit">Połącz i sprawdź</button>
                        <button type="button" class="secondary" id="creds-forget" hidden>Zapomnij dane</button>
                    </div>
                    <p class="muted" id="creds-status"></p>
                </form>
            </div>

            <!-- Filtry (wspólne dla wszystkich zakładek) -->
            <section class="filters card">
                <div class="field"><label for="f-from">Od</label><input type="date" id="f-from"></div>
                <div class="field"><label for="f-to">Do</label><input type="date" id="f-to"></div>
                <div class="field">
                    <label for="f-priority">Priorytet (raport główny)</label>
                    <select id="f-priority"><option value="">— ładowanie —</option></select>
                </div>
                <button id="f-apply">Pokaż</button>
                <button id="f-csv" class="secondary" title="Pobierz raport główny jako CSV">Eksport CSV</button>
            </section>

            <!-- ============ ZAKŁADKA: PRZEGLĄD ============ -->
            <div class="tab-panel" data-tab="overview">
                <div class="kpi-row hero">
                    <div class="kpi accent"><div class="kpi-label">Zgłoszenia otwarte</div><div class="kpi-value" id="ov-open">—</div><div class="kpi-sub">stan bieżący</div></div>
                    <div class="kpi"><div class="kpi-label">Zamknięte (zakres)</div><div class="kpi-value" id="ov-closed">—</div><div class="kpi-sub">w wybranym okresie</div></div>
                    <div class="kpi"><div class="kpi-label">Śr. czas 1. odpowiedzi</div><div class="kpi-value" id="ov-fr">—</div><div class="kpi-sub">otwarcie → 1. odpowiedź</div></div>
                    <div class="kpi"><div class="kpi-label">Śr. czas rozwiązania</div><div class="kpi-value" id="ov-res">—</div><div class="kpi-sub">otwarcie → zamknięcie</div></div>
                </div>
                <div class="grid-2">
                    <section class="card"><h2>Wolumen zgłoszeń</h2><div class="chart-box"><canvas id="chart-volume"></canvas></div></section>
                    <section class="card"><h2>Rozkład wg priorytetu</h2><div class="chart-box"><canvas id="chart-priority"></canvas></div></section>
                </div>
            </div>

            <!-- ============ ZAKŁADKA: PRIORYTETY ============ -->
            <div class="tab-panel" data-tab="priorities" hidden>
                <div class="banner info">
                    ℹ️ Priorytety wdrożono firmowo w <strong>kwietniu 2026</strong> (wdrożenie ukończone 29.04.2026) —
                    jeden dział miał je już od grudnia 2025, więc dane sprzed kwietnia mogą mieć domyślny priorytet
                    w zależności od działu.
                    <button type="button" class="linklike" id="jump-to-rollout">Ustaw zakres „od wdrożenia" (29.04.2026)</button>
                </div>

                <!-- RANKING: kto ma najgorsze czasy na priorytetowych zgłoszeniach -->
                <section class="card highlight-card">
                    <h2>🏆 Ranking pracowników — priorytetowe zgłoszenia</h2>
                    <p class="section-hint">
                        Liczy się <strong>obie</strong> miary: czas do pierwszej odpowiedzi ORAZ czas do faktycznego
                        zamknięcia ticketa — żeby nie dało się „odpisać i zniknąć". Kliknij wiersz agenta, aby zobaczyć
                        pełną listę jego ticketów i sprawdzić, czy średnią zawyżył pojedynczy przypadek.
                    </p>
                    <div class="bd-controls">
                        <div class="field">
                            <label>Priorytety</label>
                            <div class="msel" id="pr-priority-msel"></div>
                        </div>
                        <div class="field">
                            <label>Działy</label>
                            <div class="msel" id="pr-dept-msel"></div>
                        </div>
                        <div class="field">
                            <label>Konta</label>
                            <label class="switch">
                                <input type="checkbox" id="pr-active">
                                <span class="slider"></span>
                                <span class="switch-label" id="pr-active-label">wszystkie</span>
                            </label>
                        </div>
                    </div>
                    <p class="section-hint" id="pr-note"></p>
                    <div class="table-scroll">
                        <table class="grid" id="pr-ranking-table">
                            <thead>
                                <tr>
                                    <th>Pracownik</th><th>Ticketów</th>
                                    <th>Śr. czas 1. odp.</th><th>Najgorszy przypadek</th>
                                    <th>Śr. czas rozwiązania</th><th>Najgorszy przypadek</th>
                                </tr>
                            </thead>
                            <tbody><tr><td colspan="6" class="muted">Ładowanie…</td></tr></tbody>
                        </table>
                    </div>
                    <div id="pr-detail" class="pr-detail" hidden>
                        <div class="pr-detail-head">
                            <h3 id="pr-detail-title">Tickety pracownika</h3>
                            <button type="button" class="secondary" id="pr-detail-close">Zwiń</button>
                        </div>
                        <p class="section-hint">Posortowane od najdłuższego czasu rozwiązania — wiersz zaznaczony na czerwono to ten, który najbardziej zawyżył średnią.</p>
                        <div class="table-scroll">
                            <table class="grid" id="pr-detail-table">
                                <thead>
                                    <tr><th>Nr</th><th>Temat</th><th>Zgłaszający</th><th>Priorytet</th><th>Otwarcie</th><th>Czas do 1. odp.</th><th>Zamknięcie</th><th>Czas rozwiązania</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <h2>Zamknięte zgłoszenia o wybranym priorytecie</h2>
                    <p class="section-hint" id="main-summary">Czasy dla zamkniętych ticketów wybranego priorytetu.</p>
                    <details id="main-details">
                        <summary><span class="chev" aria-hidden="true">▸</span><span>Lista ticketów (kliknij, aby rozwinąć)</span></summary>
                        <div class="table-scroll" style="margin-top:14px">
                            <table class="grid" id="main-table">
                                <thead>
                                    <tr>
                                        <th>Nr</th><th>Temat</th><th>Zgłaszający</th><th>Agent</th>
                                        <th>Otwarcie</th><th>1. odpowiedź</th><th>Czas do 1. odp.</th>
                                        <th>Zamknięcie</th><th>Czas rozwiązania</th>
                                    </tr>
                                </thead>
                                <tbody><tr><td colspan="9" class="muted">Wybierz priorytet i kliknij „Pokaż".</td></tr></tbody>
                            </table>
                        </div>
                    </details>
                </section>

                <section class="card">
                    <h2>Analityka zamkniętych ticketów</h2>
                    <p class="section-hint">Liczone dla ticketów <strong>zamkniętych</strong> w wybranym zakresie dat.</p>
                    <div class="kpi-row">
                        <div class="kpi accent"><div class="kpi-label">Zamkniętych</div><div class="kpi-value" id="kpi-closed">—</div></div>
                        <div class="kpi"><div class="kpi-label">Śr. czas rozwiązania</div><div class="kpi-value" id="kpi-resolution">—</div></div>
                        <div class="kpi"><div class="kpi-label">Śr. czas 1. odpowiedzi</div><div class="kpi-value" id="kpi-firstresp">—</div></div>
                        <div class="kpi"><div class="kpi-label">Z odpowiedzią</div><div class="kpi-value" id="kpi-withresp">—</div></div>
                    </div>
                </section>

                <div class="grid-2">
                    <section class="card"><h2>Śr. czas 1. odpowiedzi wg priorytetu</h2><p class="section-hint">otwarcie → pierwsza odpowiedź agenta</p><div class="chart-box"><canvas id="chart-closed-frtime"></canvas></div></section>
                    <section class="card"><h2>Śr. czas rozwiązania wg priorytetu</h2><p class="section-hint">otwarcie → zamknięcie</p><div class="chart-box"><canvas id="chart-closed-restime"></canvas></div></section>
                </div>
                <div class="grid-2">
                    <section class="card"><h2>Zamknięte wg priorytetu</h2><div class="chart-box"><canvas id="chart-closed-priority"></canvas></div></section>
                    <section class="card"><h2>Zamknięte w czasie</h2><div class="chart-box"><canvas id="chart-closed-time"></canvas></div></section>
                </div>

                <section class="card">
                    <h2>Zamknięte wg działu</h2>
                    <div class="table-scroll" style="margin-top:12px">
                        <table class="grid" id="closed-dept-table">
                            <thead><tr><th>Dział</th><th>Zamkniętych</th><th>Śr. czas rozwiązania</th></tr></thead>
                            <tbody><tr><td colspan="3" class="muted">Ładowanie…</td></tr></tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- ============ ZAKŁADKA: PRACOWNICY ============ -->
            <div class="tab-panel" data-tab="people" hidden>
                <section class="card">
                    <h2>🌡️ Mapa cieplna czasów — wg wymiaru i priorytetu</h2>
                    <p class="section-hint">
                        Kolor komórki pokazuje pozycję względem innych w tej samej kolumnie priorytetu —
                        <span class="heat-legend-inline"><i class="heat-chip" style="background:#c9ecd7"></i>szybciej</span>
                        <span class="heat-legend-inline"><i class="heat-chip" style="background:#ffe9b3"></i>przeciętnie</span>
                        <span class="heat-legend-inline"><i class="heat-chip" style="background:#fbd2d2"></i>wolniej</span>
                        — więc od razu widać, kto najbardziej odstaje przy danym priorytecie.
                    </p>
                    <div class="dim-tabs" id="dim-tabs">
                        <button type="button" class="dim-tab active" data-dim="staff">Pracownicy</button>
                        <button type="button" class="dim-tab" data-dim="user">Użytkownicy</button>
                        <button type="button" class="dim-tab" data-dim="team">Zespoły</button>
                        <button type="button" class="dim-tab" data-dim="dept">Oddziały</button>
                    </div>
                    <div class="bd-controls">
                        <div class="field">
                            <label>Miara czasu</label>
                            <div class="metric-tabs" id="bd-metric-tabs">
                                <button type="button" class="metric-tab active" data-metric="avg_first_response">⏱ Pierwsza odpowiedź</button>
                                <button type="button" class="metric-tab" data-metric="avg_resolution">🏁 Otwarcie → Zamknięcie</button>
                                <button type="button" class="metric-tab compact" data-metric="sum_resolution">Suma czasu</button>
                                <button type="button" class="metric-tab compact" data-metric="count">Liczba ticketów</button>
                            </div>
                        </div>
                        <div class="field bd-staff-only">
                            <label>Działy</label>
                            <div class="msel" id="bd-dept-msel"></div>
                        </div>
                        <div class="field bd-staff-only">
                            <label>Konta</label>
                            <label class="switch"><input type="checkbox" id="bd-active" checked><span class="slider"></span><span class="switch-label" id="bd-active-label">tylko włączone</span></label>
                        </div>
                    </div>
                    <p class="section-hint" id="bd-note"></p>
                    <div class="table-scroll">
                        <table class="grid" id="bd-table"><thead></thead><tbody><tr><td class="muted">Ładowanie…</td></tr></tbody></table>
                    </div>
                </section>

                <div class="grid-2">
                    <section class="card"><h2>Kto obsługuje najwięcej (agenci)</h2><div class="chart-box tall"><canvas id="chart-agent"></canvas></div></section>
                    <section class="card"><h2>Kto zgłasza najwięcej</h2><div class="chart-box tall"><canvas id="chart-submitter"></canvas></div></section>
                </div>

                <section class="card">
                    <h2>Agenci wg działów</h2>
                    <p class="section-hint" id="dept-summary"></p>
                    <div id="dept-container"><p class="muted">Ładowanie…</p></div>
                    <div class="legend-inline">
                        <span><i class="dot on"></i> aktywny</span>
                        <span><i class="dot off"></i> nieaktywny</span>
                    </div>
                </section>
            </div>

            <!-- ============ ZAKŁADKA: OCENY ============ -->
            <div class="tab-panel" data-tab="ratings" hidden>
                <section class="card">
                    <h2>Oceny ticketów</h2>
                    <p class="section-hint" id="rating-note">Ładowanie…</p>
                    <div class="kpi-row" id="rating-kpis" hidden>
                        <div class="kpi accent"><div class="kpi-label">Średnia ocena</div><div class="kpi-value" id="kpi-rating-avg">—</div></div>
                        <div class="kpi"><div class="kpi-label">Ocenionych</div><div class="kpi-value" id="kpi-rating-count">—</div></div>
                        <div class="kpi"><div class="kpi-label">% ocenionych</div><div class="kpi-value" id="kpi-rating-pct">—</div></div>
                        <div class="kpi"><div class="kpi-label">Zamkniętych</div><div class="kpi-value" id="kpi-rating-total">—</div></div>
                    </div>
                    <p class="section-hint" style="margin-top:14px">💡 Kliknij słupek, aby przeczytać tickety z daną oceną.</p>
                    <div class="chart-box"><canvas id="chart-rating"></canvas></div>
                </section>

                <section class="card" id="rating-detail" hidden>
                    <h2 id="rating-detail-title">Tickety z oceną</h2>
                    <div class="table-scroll" style="margin-top:12px">
                        <table class="grid" id="rating-detail-table">
                            <thead>
                                <tr><th>Nr</th><th>Temat</th><th>Zgłaszający</th><th>Agent</th><th>Otwarcie</th><th>Czas do 1. odp.</th><th>Zamknięcie</th><th>Czas rozwiązania</th></tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- ============ ZAKŁADKA: KONTROLA JAKOŚCI ============ -->
            <div class="tab-panel" data-tab="quality" hidden>
                <section class="card highlight-card">
                    <h2>🔍 Szybka odpowiedź, późne zamknięcie</h2>
                    <p class="section-hint">
                        Klasyczny wzorzec: agent odpowiada błyskawicznie i od razu zamyka ticket, ale klient wraca
                        po tygodniach lub miesiącach („zapomniałem pobrać, proszę jeszcze raz") i sprawa faktycznie
                        kończy się dużo później, niż sugeruje pierwsza odpowiedź. Poniżej: tickety, gdzie
                        <strong>pierwsza odpowiedź</strong> była szybsza niż próg, a <strong>faktyczne zamknięcie</strong>
                        przyszło zauważalnie później — posortowane od największej rozbieżności.
                    </p>
                    <div class="qc-controls">
                        <label for="qc-threshold">Próg „szybkiej" pierwszej odpowiedzi: <strong id="qc-threshold-value">15 min</strong></label>
                        <input type="range" id="qc-threshold" min="1" max="240" step="1" value="15">
                        <div class="qc-threshold-scale"><span>1 min</span><span>1 g</span><span>2 g</span><span>4 g</span></div>
                    </div>
                    <p class="section-hint" id="qc-note"></p>
                    <div class="table-scroll">
                        <table class="grid" id="qc-table">
                            <thead>
                                <tr>
                                    <th>Nr</th><th>Temat</th><th>Zgłaszający</th><th>Agent</th><th>Priorytet</th>
                                    <th>Otwarcie</th><th>Pierwsza odpowiedź</th><th>Zamknięcie</th>
                                    <th>Odstęp odpowiedź → zamknięcie</th><th>Ponownie otwarte</th>
                                </tr>
                            </thead>
                            <tbody><tr><td colspan="10" class="muted">Ładowanie…</td></tr></tbody>
                        </table>
                    </div>
                </section>
            </div>

        </main>
    </div>
</div>

<script>
    window.OSTA = {
        mode: <?= json_encode((string) cfg('db.mode', 'config')) ?>,
        authEnabled: <?= auth_enabled() ? 'true' : 'false' ?>,
        defaultDept: <?= json_encode((string) cfg('app.default_department', '')) ?>
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
