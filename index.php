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
    <strong><?= $title ?></strong>
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

</main>

<script>
    window.OSTA = {
        mode: <?= json_encode((string) cfg('db.mode', 'config')) ?>,
        authEnabled: <?= auth_enabled() ? 'true' : 'false' ?>
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
