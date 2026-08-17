<?php
/**
 * Strona diagnostyczna: sprawdza połączenie z bazą, prefiks tabel,
 * wersję osTicketa oraz dostępne priorytety/statusy.
 * Otwórz w przeglądarce po wgraniu config.php.
 */

require __DIR__ . '/lib/bootstrap.php';
auth_require_page();

$title = htmlspecialchars((string) cfg('app.title', 'osTicket — Statystyki'), ENT_QUOTES);

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }

$mode = (string) cfg('db.mode', 'config');

$checks = [];
$fatal  = null;

// W trybie testowym backend nie ma danych do bazy (są podawane w okienku na dashboardzie).
if ($mode === 'prompt') {
    ?>
    <!doctype html>
    <html lang="pl"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnostyka — <?= $title ?></title>
    <link rel="stylesheet" href="assets/styles.css"></head>
    <body>
    <header class="simple-top"><strong><?= $title ?></strong>
        <nav><a href="index.php">Dashboard</a><a href="diagnostics.php" class="active">Diagnostyka</a>
        <?php if (auth_enabled()): ?><a href="logout.php">Wyloguj</a><?php endif; ?></nav>
    </header>
    <main class="container">
        <div class="banner warn">
            Aplikacja działa w <strong>trybie testowym</strong> (<code>db.mode = 'prompt'</code>).
            Dane do bazy podajesz w okienku na <a href="index.php">dashboardzie</a>, a test połączenia
            i podsumowanie schematu zobaczysz właśnie tam po kliknięciu „Połącz i sprawdź".
        </div>
    </main></body></html>
    <?php
    exit;
}

try {
    db(); // wymuś połączenie
    $checks[] = ['ok' => true, 'label' => 'Połączenie z bazą', 'detail' => cfg('db.name')];
} catch (Throwable $e) {
    $fatal = $e->getMessage();
}

$version    = null;
$prefix     = (string) cfg('db.prefix', 'ost_');
$guessed    = null;
$tables     = [];
$priorities = [];
$statuses   = [];
$prioritySrc = null;
$ratingField = null;
$formFields  = [];
$reopenSrc   = null;
$threadEventCols = [];

if ($fatal === null) {
    try {
        $tables      = schema_required_tables();
        $version     = schema_osticket_version();
        $priorities  = schema_priorities();
        $statuses    = schema_statuses();
        $prioritySrc = schema_priority_source();
        $ratingField = schema_rating_field();
        $formFields  = schema_form_fields();
        $reopenSrc   = schema_reopen_event_source();
        $threadEventCols = schema_thread_event_columns();
        if (empty($tables[tbl('ticket')])) {
            $guessed = schema_guess_prefix();
        }
    } catch (Throwable $e) {
        $fatal = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnostyka — <?= $title ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<header class="simple-top">
    <strong><?= $title ?></strong>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="diagnostics.php" class="active">Diagnostyka</a>
        <?php if (auth_enabled()): ?><a href="logout.php">Wyloguj</a><?php endif; ?>
    </nav>
</header>

<main class="container">
    <h1>Diagnostyka</h1>

    <?php if (!auth_enabled()): ?>
        <div class="banner warn">
            ⚠️ Panel jest <strong>bez hasła</strong> (pusty <code>auth.password_hash</code> w config.php).
            W internecie ustaw hasło — instrukcja w README.
        </div>
    <?php endif; ?>

    <?php if ($fatal !== null): ?>
        <div class="banner error">
            ❌ Błąd: <?= h($fatal) ?>
        </div>
        <p>Sprawdź dane w <code>config.php</code> (nazwa bazy, użytkownik, hasło).</p>
    <?php else: ?>

        <section class="card">
            <h2>Podstawy</h2>
            <table class="kv">
                <tr><td>Wersja osTicketa</td><td><?= $version ? h($version) : '<span class="muted">nieznana</span>' ?></td></tr>
                <tr><td>Prefiks tabel (config)</td><td><code><?= h($prefix) ?></code></td></tr>
                <tr><td>Źródło priorytetu</td><td>
                    <?= $prioritySrc ? '<code>' . h($prioritySrc['table'] . '.' . $prioritySrc['column']) . '</code>' : '<span class="error">nie wykryto</span>' ?>
                </td></tr>
            </table>
            <?php if ($guessed !== null && $guessed !== $prefix): ?>
                <div class="banner warn">
                    Nie znaleziono tabeli <code><?= h(tbl('ticket')) ?></code>.
                    Wykryty prefiks to prawdopodobnie <code><?= h($guessed) ?></code> —
                    ustaw go w <code>config.php</code> (klucz <code>db.prefix</code>).
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Wymagane tabele</h2>
            <table class="kv">
                <?php foreach ($tables as $name => $exists): ?>
                    <tr>
                        <td><code><?= h($name) ?></code></td>
                        <td><?= $exists ? '<span class="ok">✔ jest</span>' : '<span class="error">✘ brak</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="muted">Tabela <code><?= h(tbl('ticket__cdata')) ?></code> bywa opcjonalna (zależnie od wersji).</p>
        </section>

        <section class="card">
            <h2>Priorytety (użyj priority_id w raporcie)</h2>
            <?php if ($priorities): ?>
                <table class="grid">
                    <thead><tr><th>priority_id</th><th>tag</th><th>opis</th><th>urgency</th></tr></thead>
                    <tbody>
                    <?php foreach ($priorities as $p): ?>
                        <tr>
                            <td><?= h($p['priority_id']) ?></td>
                            <td><code><?= h($p['priority']) ?></code></td>
                            <td><?= h($p['priority_desc']) ?></td>
                            <td><?= h($p['priority_urgency']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="error">Brak danych o priorytetach.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Statusy</h2>
            <?php if ($statuses): ?>
                <table class="grid">
                    <thead><tr><th>id</th><th>nazwa</th><th>state</th></tr></thead>
                    <tbody>
                    <?php foreach ($statuses as $s): ?>
                        <tr>
                            <td><?= h($s['id']) ?></td>
                            <td><?= h($s['name']) ?></td>
                            <td><code><?= h($s['state']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="error">Brak danych o statusach.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Ocena ticketu (gwiazdki)</h2>
            <?php if ($ratingField !== null): ?>
                <p>Wykryte pole oceny w <code><?= h(tbl('ticket__cdata')) ?></code>:
                   <code><?= h($ratingField) ?></code> ✔</p>
            <?php else: ?>
                <div class="banner warn">
                    Nie wykryto pola z oceną. Znajdź poniżej pole z gwiazdkami i wpisz jego
                    <strong>nazwę</strong> (kolumna) w <code>config.php</code> → <code>rating.field</code>.
                </div>
            <?php endif; ?>
            <?php if ($formFields): ?>
                <table class="grid">
                    <thead><tr><th>name (kolumna)</th><th>etykieta</th><th>typ</th></tr></thead>
                    <tbody>
                    <?php foreach ($formFields as $f): ?>
                        <tr>
                            <td><code><?= h($f['name']) ?></code></td>
                            <td><?= h($f['label']) ?></td>
                            <td><?= h($f['type']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="muted">Brak tabeli pól formularza (<code><?= h(tbl('form_field')) ?></code>).</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Ponowne otwarcia (zakładka „Kontrola jakości")</h2>
            <?php if ($reopenSrc !== null): ?>
                <p>Wykryto historię ponownych otwarć w <code><?= h($reopenSrc['table']) ?>.<?= h($reopenSrc['column']) ?></code> ✔
                   — kolumna „Ponownie otwarte" pokaże rzeczywiste liczby, nie tylko odstęp czasowy.</p>
            <?php else: ?>
                <div class="banner warn">
                    Nie wykryto tabeli/kolumny z historią ponownych otwarć (szukano <code><?= h(tbl('thread_event')) ?></code>
                    z wpisami pasującymi do „reopen"). Sekcja „Kontrola jakości" i tak działa — odsiewa tickety po
                    odstępie między pierwszą odpowiedzią a zamknięciem, tylko bez potwierdzenia z historii statusów.
                </div>
                <?php if ($threadEventCols): ?>
                    <p class="muted">
                        Tabela <code><?= h(tbl('thread_event')) ?></code> istnieje — poniżej jej kolumny. Jeśli widzisz
                        tu kolumnę, która przechowuje typ zdarzenia (np. status/state/event), daj znać jej nazwę i
                        przykładową wartość dla „ponowne otwarcie" — dopniemy wykrywanie pod Waszą wersję osTicketa.
                    </p>
                    <table class="grid">
                        <thead><tr><th>kolumna</th><th>typ</th></tr></thead>
                        <tbody>
                        <?php foreach ($threadEventCols as $c): ?>
                            <tr>
                                <td><code><?= h($c['column_name'] ?? ($c['COLUMN_NAME'] ?? '')) ?></code></td>
                                <td><?= h($c['data_type'] ?? ($c['DATA_TYPE'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="muted">
                        Tabela <code><?= h(tbl('thread_event')) ?></code> w ogóle nie istnieje w tej instalacji —
                        to normalne w starszych wersjach osTicketa. Historia ponownych otwarć po prostu nie jest
                        dostępna, „Kontrola jakości" działa wyłącznie na odstępie czasowym.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php endif; ?>
</main>
</body>
</html>
