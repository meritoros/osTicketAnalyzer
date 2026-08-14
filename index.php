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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
