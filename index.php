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
<header class="topbar">
    <div class="brand">
        <?php $logo = (string) cfg('app.logo_url', ''); if ($logo !== ''): ?>
            <img src="<?= htmlspecialchars($logo, ENT_QUOTES) ?>" alt="Logo" class="brand-logo"
                 onerror="this.style.display='none'">
        <?php endif; ?>
        <strong><?= $title ?></strong>
    </div>
    <nav>
        <a href="index.php" class="active">Dashboard</a>
        <a href="diagnostics.php">Diagnostyka</a>
        <?php if (auth_enabled()): ?><a href="logout.php">Wyloguj</a><?php endif; ?>
    </nav>
</header>

<main class="container">

    <!-- Okienko na dane do bazy (tryb testowy 'prompt') -->
    <div id="creds-modal" class="modal-overlay" hidden>
        <form class="modal-card" id="creds-form" autocomplete="off">
            <h2>Dane do bazy (tryb testowy)</h2>
            <p class="muted">
                Wpisywane dane trafiają tylko do tej sesji przeglądarki i są wysyłane do
                backendu przez HTTPS — <strong>nic nie jest zapisywane</strong> na serwerze ani w repo.
            </p>
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

    <section class="filters card">
        <div class="field">
            <label for="f-from">Od</label>
            <input type="date" id="f-from">
        </div>
        <div class="field">
            <label for="f-to">Do</label>
            <input type="date" id="f-to">
        </div>
        <div class="field">
            <label for="f-priority">Priorytet (raport główny)</label>
            <select id="f-priority"><option value="">— ładowanie —</option></select>
        </div>
        <button id="f-apply">Pokaż</button>
        <button id="f-csv" class="secondary" title="Pobierz raport główny jako CSV">Eksport CSV</button>
    </section>

    <!-- RAPORT GŁÓWNY (dla kierownika) -->
    <section class="card">
        <h2>Zamknięte zgłoszenia o wybranym priorytecie — czas pierwszej odpowiedzi</h2>
        <p class="muted" id="main-summary"></p>
        <div class="table-scroll">
            <table class="grid" id="main-table">
                <thead>
                    <tr>
                        <th>Nr</th>
                        <th>Temat</th>
                        <th>Zgłaszający</th>
                        <th>Agent</th>
                        <th>Data zgłoszenia</th>
                        <th>Pierwsza odpowiedź</th>
                        <th>Czas do 1. odpowiedzi</th>
                        <th>Data zamknięcia</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8" class="muted">Wybierz priorytet i kliknij „Pokaż".</td></tr></tbody>
            </table>
        </div>
    </section>

    <div class="grid-2">
        <section class="card">
            <h2>Wolumen zgłoszeń</h2>
            <canvas id="chart-volume" height="140"></canvas>
        </section>
        <section class="card">
            <h2>Rozkład wg priorytetu</h2>
            <canvas id="chart-priority" height="140"></canvas>
        </section>
        <section class="card">
            <h2>Kto obsługuje najwięcej (agenci)</h2>
            <canvas id="chart-agent" height="160"></canvas>
        </section>
        <section class="card">
            <h2>Kto zgłasza najwięcej</h2>
            <canvas id="chart-submitter" height="160"></canvas>
        </section>
    </div>

    <!-- WYNIKI WG WYMIARU (pracownicy / użytkownicy / zespoły / oddziały) -->
    <section class="card">
        <h2>Wyniki wg wymiaru</h2>
        <div class="dim-tabs" id="dim-tabs">
            <button type="button" class="dim-tab active" data-dim="staff">Pracownicy</button>
            <button type="button" class="dim-tab" data-dim="user">Użytkownicy</button>
            <button type="button" class="dim-tab" data-dim="team">Zespoły</button>
            <button type="button" class="dim-tab" data-dim="dept">Oddziały</button>
        </div>
        <div class="bd-controls">
            <div class="field">
                <label for="bd-metric">Metryka</label>
                <select id="bd-metric">
                    <option value="avg_resolution">Śr. czas rozwiązania</option>
                    <option value="avg_first_response">Śr. czas 1. odpowiedzi</option>
                    <option value="sum_resolution">Suma czasu rozwiązania</option>
                    <option value="count">Liczba ticketów</option>
                </select>
            </div>
            <div class="field bd-staff-only">
                <label for="bd-dept">Działy (wielokrotny wybór)</label>
                <select id="bd-dept" multiple size="4"></select>
            </div>
            <div class="field bd-staff-only">
                <label>Konta</label>
                <label class="switch">
                    <input type="checkbox" id="bd-active" checked>
                    <span class="slider"></span>
                    <span class="switch-label" id="bd-active-label">tylko włączone</span>
                </label>
            </div>
        </div>
        <p class="muted" id="bd-note"></p>
        <div class="table-scroll">
            <table class="grid" id="bd-table">
                <thead></thead>
                <tbody><tr><td class="muted">Ładowanie…</td></tr></tbody>
            </table>
        </div>
    </section>

    <!-- OCENY TICKETÓW (gwiazdki 1–5) -->
    <section class="card">
        <h2>Oceny ticketów</h2>
        <p class="muted" id="rating-note">Ładowanie…</p>
        <div class="kpi-row" id="rating-kpis" hidden>
            <div class="kpi accent"><div class="kpi-label">Średnia ocena</div><div class="kpi-value" id="kpi-rating-avg">—</div></div>
            <div class="kpi"><div class="kpi-label">Ocenionych</div><div class="kpi-value" id="kpi-rating-count">—</div></div>
            <div class="kpi"><div class="kpi-label">% ocenionych</div><div class="kpi-value" id="kpi-rating-pct">—</div></div>
            <div class="kpi"><div class="kpi-label">Zamkniętych</div><div class="kpi-value" id="kpi-rating-total">—</div></div>
        </div>
        <canvas id="chart-rating" height="110"></canvas>
    </section>

    <!-- AGENCI WG DZIAŁÓW (aktywni / nieaktywni) -->
    <section class="card">
        <h2>Agenci wg działów</h2>
        <p class="muted" id="dept-summary"></p>
        <div id="dept-container"><p class="muted">Ładowanie…</p></div>
        <div class="legend-inline">
            <span><i class="dot on"></i> aktywny</span>
            <span><i class="dot off"></i> nieaktywny</span>
        </div>
    </section>

    <!-- ANALITYKA ZAMKNIĘTYCH TICKETÓW (filtr po dacie zamknięcia) -->
    <section class="card">
        <h2>Analityka zamkniętych ticketów</h2>
        <p class="muted">Liczone dla ticketów <strong>zamkniętych</strong> w wybranym zakresie dat.</p>
        <div class="kpi-row">
            <div class="kpi accent">
                <div class="kpi-label">Zamkniętych</div>
                <div class="kpi-value" id="kpi-closed">—</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Śr. czas rozwiązania</div>
                <div class="kpi-value" id="kpi-resolution">—</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Śr. czas 1. odpowiedzi</div>
                <div class="kpi-value" id="kpi-firstresp">—</div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Z odpowiedzią</div>
                <div class="kpi-value" id="kpi-withresp">—</div>
            </div>
        </div>
    </section>

    <div class="grid-2">
        <section class="card">
            <h2>Zamknięte wg priorytetu</h2>
            <canvas id="chart-closed-priority" height="150"></canvas>
        </section>
        <section class="card">
            <h2>Śr. czas rozwiązania wg priorytetu</h2>
            <canvas id="chart-closed-restime" height="150"></canvas>
        </section>
    </div>

    <section class="card">
        <h2>Zamknięte w czasie</h2>
        <canvas id="chart-closed-time" height="110"></canvas>
    </section>

    <section class="card">
        <h2>Zamknięte wg działu</h2>
        <div class="table-scroll">
            <table class="grid" id="closed-dept-table">
                <thead><tr><th>Dział</th><th>Zamkniętych</th><th>Śr. czas rozwiązania</th></tr></thead>
                <tbody><tr><td colspan="3" class="muted">Ładowanie…</td></tr></tbody>
            </table>
        </div>
    </section>

</main>

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
