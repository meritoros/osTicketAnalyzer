<?php
/**
 * Zapytania raportowe. Każda funkcja zwraca zwykłą tablicę wierszy (do JSON-a).
 * Nazwy tabel pochodzą z zaufanego prefiksu, wartości od użytkownika — tylko przez bind.
 */

declare(strict_types=1);

/** Buduje fragment WHERE dla zakresu dat po dowolnej kolumnie (domyślnie t.created). */
function reports_range(string $col, ?string $from, ?string $to, string &$types, array &$params): string
{
    $sql = '';
    if ($from !== null && $from !== '') {
        $sql .= " AND $col >= ? ";
        $types .= 's';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== null && $to !== '') {
        $sql .= " AND $col <= ? ";
        $types .= 's';
        $params[] = $to . ' 23:59:59';
    }
    return $sql;
}

/** Zakres dat po kolumnie t.created (zgłoszenie). */
function reports_date_where(?string $from, ?string $to, string &$types, array &$params): string
{
    return reports_range('t.created', $from, $to, $types, $params);
}

/**
 * Fragment SQL: LEFT JOIN wątku + podzapytania z pierwszą odpowiedzią agenta.
 * Daje alias fr.first_response_at (najwcześniejsza odpowiedź człowieka w wątku).
 */
function reports_first_response_join(): string
{
    $TH = tbl('thread');
    $TE = tbl('thread_entry');
    return " LEFT JOIN $TH th ON th.object_id = t.ticket_id AND th.object_type = 'T'
             LEFT JOIN (
                 SELECT te.thread_id, MIN(te.created) AS first_response_at
                 FROM $TE te
                 WHERE te.type = 'R' AND te.staff_id > 0
                 GROUP BY te.thread_id
             ) fr ON fr.thread_id = th.id ";
}

/** Wyrażenie SQL wskazujące kolumnę z priorytetem ticketu (t.* albo cd.*). */
function reports_priority_expr(?array $ps): ?string
{
    if ($ps === null) {
        return null;
    }
    return ($ps['joinOnTicket'] ? 'cd.' : 't.') . $ps['column'];
}

/**
 * Fragment "AND priCol IN (?,?,...)" dla wielokrotnego wyboru priorytetów.
 * Pusta lista albo brak wykrytej kolumny priorytetu = brak filtra (wszystkie).
 */
function reports_priority_filter(?string $priCol, array $priorityIds, string &$types, array &$params): string
{
    if ($priCol === null || empty($priorityIds)) {
        return '';
    }
    $place = implode(',', array_fill(0, count($priorityIds), '?'));
    foreach ($priorityIds as $id) { $types .= 'i'; $params[] = (int) $id; }
    return " AND $priCol IN ($place) ";
}

/**
 * Próg (w dniach) powyżej którego czas "otwarcie→zamknięcie" traktujemy jako
 * „wznowiony po długiej przerwie" — ticket sprzed lat, do którego klient
 * wraca z nową, niezwiązaną sprawą (np. "proszę o przedłużenie certyfikatu"
 * na tickecie z 2024 roku), odejmując TIMESTAMPDIFF(t.created, t.closed)
 * dałby "czas rozwiązania" liczony w latach, co zawyżałoby uśrednienia.
 * Taki ticket nadal liczy się jako zamknięty w okresie — wyklucza się
 * TYLKO z uśrednień czasu, nigdy z liczby zamkniętych. Konfigurowalne przez
 * config.php -> reports.max_resolution_days (domyślnie 14 dni — zgodnie
 * z decyzją zespołu: ticket wznowiony po dłuższej przerwie niż dwa tygodnie
 * traktujemy jako "inną sprawę", a nie ciągłość tej samej obsługi).
 */
function reports_max_resolution_days(): int
{
    $v = (int) cfg('reports.max_resolution_days', 14);
    return $v > 0 ? $v : 14;
}

/**
 * Wyrażenie SQL czasu rozwiązania (sekundy) do UŚREDNIEŃ/SUM — zwraca NULL
 * (pomijane przez AVG/SUM) dla ticketów przekraczających próg „wznowiony po
 * długiej przerwie". Do surowych list (per-ticket) używaj zwykłego
 * TIMESTAMPDIFF(SECOND, t.created, t.closed) + reports_resolution_outlier_expr()
 * jako osobnej flagi — tam chcemy pokazać prawdziwą wartość, tylko oznaczoną.
 */
function reports_resolution_seconds_agg_expr(): string
{
    $days = reports_max_resolution_days();
    return "(CASE WHEN TIMESTAMPDIFF(DAY, t.created, t.closed) <= $days
                   THEN TIMESTAMPDIFF(SECOND, t.created, t.closed) END)";
}

/** Wyrażenie SQL: 1 gdy ticket przekracza próg „wznowiony po długiej przerwie", inaczej 0. */
function reports_resolution_outlier_expr(): string
{
    $days = reports_max_resolution_days();
    return "(CASE WHEN TIMESTAMPDIFF(DAY, t.created, t.closed) > $days THEN 1 ELSE 0 END)";
}

/**
 * RAPORT GŁÓWNY: zgłoszenia o wybranym priorytecie wraz z datą/godziną
 * zgłoszenia, pierwszej odpowiedzi i czasem do niej.
 *
 * @param string $basis 'closed' (domyślnie) — ticket liczy się do okresu,
 *               w którym został ZAMKNIĘTY, i musi być już zamknięty (tak
 *               jak w "Analityce zamkniętych ticketów"); 'created' — ticket
 *               liczy się do okresu, w którym POWSTAŁ, niezależnie od tego,
 *               czy jest już zamknięty — żeby dało się zobaczyć też tickety
 *               utworzone w okresie, które jeszcze czekają na zamknięcie
 *               (zgłoszone przez kierownika: ticket z sierpnia zamknięty we
 *               wrześniu nie powinien "znikać" z sierpniowej statystyki).
 */
function report_high_priority_closed(array $priorityIds, ?string $from, ?string $to, string $basis = 'closed', int $limit = 2000): array
{
    $ps     = schema_priority_source();
    $priCol = reports_priority_expr($ps);

    $T  = tbl('ticket');
    $S  = tbl('ticket_status');
    $PR = tbl('ticket_priority');
    $U  = tbl('user');
    $ST = tbl('staff');
    $TH = tbl('thread');
    $TE = tbl('thread_entry');
    $CD = tbl('ticket__cdata');

    $hasCdata = $ps !== null && $ps['joinOnTicket'];

    $sql  = "SELECT
                t.ticket_id,
                t.number,
                t.created                          AS opened_at,
                t.closed                           AS closed_at,
                s.name                             AS status_name,
                pr.priority_desc                   AS priority_name,
                " . ($hasCdata ? 'cd.subject' : 'NULL') . " AS subject,
                u.name                             AS submitter,
                TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,''))) AS agent,
                fr.first_response_at,
                CASE WHEN fr.first_response_at IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at)
                     ELSE NULL END                 AS first_response_seconds,
                CASE WHEN t.closed IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, t.created, t.closed)
                     ELSE NULL END                 AS resolution_seconds,
                CASE WHEN t.closed IS NOT NULL THEN " . reports_resolution_outlier_expr() . "
                     ELSE 0 END                    AS resolution_is_outlier,
                CASE WHEN t.closed IS NOT NULL THEN 1 ELSE 0 END AS is_closed
             FROM $T t
             JOIN $S s ON s.id = t.status_id ";

    if ($hasCdata) {
        $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }

    $sql .= " LEFT JOIN $PR pr ON pr.priority_id = " . ($priCol ?? 'NULL') . "
              LEFT JOIN $U u   ON u.id = t.user_id
              LEFT JOIN $ST st ON st.staff_id = t.staff_id
              LEFT JOIN $TH th ON th.object_id = t.ticket_id AND th.object_type = 'T'
              LEFT JOIN (
                  SELECT te.thread_id, MIN(te.created) AS first_response_at
                  FROM $TE te
                  WHERE te.type = 'R' AND te.staff_id > 0
                  GROUP BY te.thread_id
              ) fr ON fr.thread_id = th.id
              WHERE 1=1 ";

    $types  = '';
    $params = [];

    if ($basis === 'created') {
        $sql .= reports_range('t.created', $from, $to, $types, $params);
        $dateCol = 't.created';
    } else {
        $sql .= ' AND t.closed IS NOT NULL ';
        $sql .= reports_range('t.closed', $from, $to, $types, $params);
        $dateCol = 't.closed';
    }
    $sql .= reports_priority_filter($priCol, $priorityIds, $types, $params);

    $limit = max(1, min($limit, 10000));
    $sql  .= " ORDER BY $dateCol DESC LIMIT " . $limit;

    return db_rows($sql, $types, $params);
}

/**
 * Wolumen zgłoszeń dziennie: UTWORZONE (wg daty zgłoszenia) i ZAMKNIĘTE
 * (wg daty faktycznego zamknięcia) — to DWA różne grupowania po różnych
 * kolumnach dat, połączone w jedną oś czasu per dzień. Liczenie „zamkniętych"
 * po dacie UTWORZENIA (jak poprzednio) było mylące: ticket zgłoszony danego
 * dnia często zamyka się tygodnie później, więc słupek „zamknięte" dla
 * wczorajszych zgłoszeń zaniżał rzeczywistą liczbę zamknięć tego dnia.
 */
function report_volume(?string $from, ?string $to): array
{
    $T = tbl('ticket');

    $typesC = ''; $paramsC = [];
    $createdSql = "SELECT DATE(t.created) AS day, COUNT(*) AS created, 0 AS closed
                    FROM $T t
                    WHERE 1=1 " . reports_range('t.created', $from, $to, $typesC, $paramsC)
                 . ' GROUP BY DATE(t.created)';

    $typesX = ''; $paramsX = [];
    // Licz po fakcie zamknięcia (t.closed), NIE po aktualnym statusie — ticket
    // później ponownie otwarty nadal LICZY SIĘ jako zamknięty tego dnia
    // (patrz komentarz przy report_closed_summary niżej: to samo założenie
    // stosujemy we wszystkich raportach „zamkniętych").
    $closedSql = "SELECT DATE(t.closed) AS day, 0 AS created, COUNT(*) AS closed
                  FROM $T t
                  WHERE t.closed IS NOT NULL " . reports_range('t.closed', $from, $to, $typesX, $paramsX)
                 . ' GROUP BY DATE(t.closed)';

    $sql = "SELECT day, SUM(created) AS created, SUM(closed) AS closed
            FROM (($createdSql) UNION ALL ($closedSql)) x
            GROUP BY day ORDER BY day ASC";

    return db_rows($sql, $typesC . $typesX, array_merge($paramsC, $paramsX));
}

/** Kto obsługuje najwięcej ticketów (agent przypisany). */
function report_by_agent(?string $from, ?string $to, int $limit = 50): array
{
    $T  = tbl('ticket');
    $ST = tbl('staff');

    $types = '';
    $params = [];
    $sql = "SELECT st.staff_id,
                   TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,''))) AS agent,
                   COUNT(*) AS total
            FROM $T t
            LEFT JOIN $ST st ON st.staff_id = t.staff_id
            WHERE t.staff_id > 0 ";
    $sql .= reports_date_where($from, $to, $types, $params);
    $sql .= ' GROUP BY st.staff_id ORDER BY total DESC LIMIT ' . max(1, min($limit, 500));

    return db_rows($sql, $types, $params);
}

/** Kto zgłasza najwięcej ticketów (zgłaszający użytkownik). */
function report_by_submitter(?string $from, ?string $to, int $limit = 50): array
{
    $T = tbl('ticket');
    $U = tbl('user');

    $types = '';
    $params = [];
    $sql = "SELECT u.id AS user_id, u.name AS submitter, COUNT(*) AS total
            FROM $T t
            LEFT JOIN $U u ON u.id = t.user_id
            WHERE 1=1 ";
    $sql .= reports_date_where($from, $to, $types, $params);
    $sql .= ' GROUP BY u.id ORDER BY total DESC LIMIT ' . max(1, min($limit, 500));

    return db_rows($sql, $types, $params);
}

/** Rozkład zgłoszeń wg priorytetu. */
function report_by_priority(?string $from, ?string $to): array
{
    $ps     = schema_priority_source();
    $priCol = reports_priority_expr($ps);
    if ($priCol === null) {
        return [];
    }

    $T  = tbl('ticket');
    $PR = tbl('ticket_priority');
    $CD = tbl('ticket__cdata');
    $hasCdata = $ps['joinOnTicket'];

    $types = '';
    $params = [];
    $sql = "SELECT pr.priority_id, pr.priority_desc AS priority_name, pr.priority_color, COUNT(*) AS total
            FROM $T t ";
    if ($hasCdata) {
        $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }
    $sql .= " LEFT JOIN $PR pr ON pr.priority_id = $priCol
              WHERE 1=1 ";
    $sql .= reports_date_where($from, $to, $types, $params);
    $sql .= " GROUP BY pr.priority_id ORDER BY total DESC";

    return db_rows($sql, $types, $params);
}

/**
 * Pracownicy (staff) w rozbiciu na działy, z informacją czy konto jest aktywne.
 * Zwraca płaską listę — grupowanie po dziale robi front.
 */
function report_staff_by_department(): array
{
    $ST = tbl('staff');
    $D  = tbl('department');

    return db_rows(
        "SELECT
             d.id                                   AS dept_id,
             COALESCE(d.name, '(bez działu)')       AS department,
             s.staff_id,
             TRIM(CONCAT(COALESCE(s.firstname,''),' ',COALESCE(s.lastname,''))) AS agent,
             s.username,
             s.email,
             s.isactive
         FROM $ST s
         LEFT JOIN $D d ON d.id = s.dept_id
         ORDER BY department ASC, s.isactive DESC, agent ASC"
    );
}

// ---------------------------------------------------------------------------
// Analityka zamkniętych ticketów (filtr po dacie ZAMKNIĘCIA t.closed)
//
// WAŻNE — definicja "zamknięty": liczymy każdy ticket, który MA znacznik
// t.closed w zakresie dat, NIEZALEŻNIE od tego, w jakim statusie jest TERAZ.
// Wcześniej te raporty dodatkowo wymagały aktualnego statusu = 'closed', co
// dawało dwa błędy zgłoszone przez kierownika:
//   1) liczby niższe niż natywny raport „Aktywność zgłoszeń" w osTickecie —
//      ticket zamknięty w danym okresie, a POTEM ponownie otwarty przez
//      klienta (patrz zakładka „Kontrola jakości"), znikał z naszych
//      raportów, mimo że faktycznie został zamknięty w tym okresie;
//   2) Mapa cieplna dawała RÓŻNE wyniki dla tego samego zakresu dat w piątek
//      i w poniedziałek — bo ticket zamknięty w piątek, a otwarty ponownie
//      w weekend, w poniedziałek już nie spełniał warunku "status=closed"
//      i wypadał z historycznego, z definicji NIEZMIENNEGO zakresu.
// Filtrowanie tylko po t.closed daje stabilny, historyczny wynik: to, co
// było zamknięte w danym okresie, zostaje zamknięte w tym okresie na zawsze,
// nawet jeśli klient później sprawę odświeżył.
// ---------------------------------------------------------------------------

/**
 * Podsumowanie: ile zamkniętych + średni czas rozwiązania, oraz osobno
 * średni czas pierwszej odpowiedzi.
 *
 * @param string $basis 'closed' (domyślnie) — wszystko zakotwiczone na dacie
 *               ZAMKNIĘCIA (ale czas 1. odpowiedzi nadal liczony wg daty
 *               samej odpowiedzi — patrz niżej); 'created' — populacja to
 *               tickety UTWORZONE w zakresie dat, niezależnie od tego, czy
 *               są już zamknięte — dodatkowo zwraca 'total' (wszystkie
 *               utworzone) i 'still_open' (ile z nich wciąż czeka).
 *
 * Niezależnie od $basis, czas pierwszej odpowiedzi liczy się do miesiąca,
 * w którym FAKTYCZNIE padła odpowiedź — ticket założony w sierpniu z
 * odpowiedzią w sierpniu, ale zamknięty we wrześniu, ma zasilać sierpniową
 * statystykę odpowiedzi, a nie wrześniową tylko dlatego, że wtedy się zamknął.
 */
function report_closed_summary(?string $from, ?string $to, string $basis = 'closed'): ?array
{
    $T = tbl('ticket');

    if ($basis === 'created') {
        $types = '';
        $params = [];
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN t.closed IS NOT NULL THEN 1 ELSE 0 END) AS total_closed,
                    SUM(CASE WHEN t.closed IS NULL THEN 1 ELSE 0 END) AS still_open,
                    AVG(CASE WHEN t.closed IS NOT NULL THEN " . reports_resolution_seconds_agg_expr() . " END) AS avg_resolution_seconds,
                    AVG(CASE WHEN fr.first_response_at IS NOT NULL
                             THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) END) AS avg_first_response_seconds,
                    SUM(CASE WHEN fr.first_response_at IS NOT NULL THEN 1 ELSE 0 END) AS with_response
                FROM $T t "
             . reports_first_response_join()
             . ' WHERE 1=1 ';
        $sql .= reports_range('t.created', $from, $to, $types, $params);
        $row = db_row($sql, $types, $params);
        return $row ?: null;
    }

    $types = '';
    $params = [];
    $sqlClosed = "SELECT
                COUNT(*) AS total_closed,
                AVG(" . reports_resolution_seconds_agg_expr() . ") AS avg_resolution_seconds
            FROM $T t
            WHERE t.closed IS NOT NULL ";
    $sqlClosed .= reports_range('t.closed', $from, $to, $types, $params);
    $closedRow = db_row($sqlClosed, $types, $params);

    $typesFr = '';
    $paramsFr = [];
    $sqlFr = "SELECT
                COUNT(*) AS with_response,
                AVG(TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at)) AS avg_first_response_seconds
            FROM $T t "
         . reports_first_response_join()
         . " WHERE fr.first_response_at IS NOT NULL ";
    $sqlFr .= reports_range('fr.first_response_at', $from, $to, $typesFr, $paramsFr);
    $frRow = db_row($sqlFr, $typesFr, $paramsFr);

    return [
        'total_closed'               => $closedRow['total_closed'] ?? 0,
        'avg_resolution_seconds'     => $closedRow['avg_resolution_seconds'] ?? null,
        'with_response'              => $frRow['with_response'] ?? 0,
        'avg_first_response_seconds' => $frRow['avg_first_response_seconds'] ?? null,
    ];
}

/**
 * Wg priorytetu: liczba i średni czas rozwiązania, średni czas 1. odpowiedzi
 * osobno (patrz komentarz przy report_closed_summary co do $basis i zasady
 * zakotwiczenia czasu 1. odpowiedzi na dacie samej odpowiedzi).
 */
function report_closed_by_priority(?string $from, ?string $to, string $basis = 'closed'): array
{
    $ps     = schema_priority_source();
    $priCol = reports_priority_expr($ps);

    $T  = tbl('ticket');
    $PR = tbl('ticket_priority');
    $CD = tbl('ticket__cdata');
    $hasCdata = $ps !== null && $ps['joinOnTicket'];

    if ($basis === 'created') {
        $types = '';
        $params = [];
        $sql = "SELECT
                    pr.priority_id,
                    pr.priority_desc AS priority_name,
                    pr.priority_color,
                    COUNT(*) AS total,
                    SUM(CASE WHEN t.closed IS NOT NULL THEN 1 ELSE 0 END) AS total_closed,
                    SUM(CASE WHEN t.closed IS NULL THEN 1 ELSE 0 END) AS still_open,
                    AVG(CASE WHEN t.closed IS NOT NULL THEN " . reports_resolution_seconds_agg_expr() . " END) AS avg_resolution_seconds,
                    AVG(CASE WHEN fr.first_response_at IS NOT NULL
                             THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) END) AS avg_first_response_seconds
                FROM $T t ";
        if ($hasCdata) {
            $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
        }
        $sql .= " LEFT JOIN $PR pr ON pr.priority_id = " . ($priCol ?? 'NULL') . " "
             . reports_first_response_join()
             . ' WHERE 1=1 ';
        $sql .= reports_range('t.created', $from, $to, $types, $params);
        $sql .= ' GROUP BY pr.priority_id ORDER BY total DESC';
        return db_rows($sql, $types, $params);
    }

    $types = '';
    $params = [];
    $sql = "SELECT
                pr.priority_id,
                pr.priority_desc AS priority_name,
                pr.priority_color,
                COUNT(*) AS total,
                AVG(" . reports_resolution_seconds_agg_expr() . ") AS avg_resolution_seconds
            FROM $T t ";
    if ($hasCdata) {
        $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }
    $sql .= " LEFT JOIN $PR pr ON pr.priority_id = " . ($priCol ?? 'NULL') . "
              WHERE t.closed IS NOT NULL ";
    $sql .= reports_range('t.closed', $from, $to, $types, $params);
    $sql .= " GROUP BY pr.priority_id";
    $rows = db_rows($sql, $types, $params);

    $typesFr = '';
    $paramsFr = [];
    $sqlFr = "SELECT
                pr.priority_id,
                AVG(TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at)) AS avg_first_response_seconds
            FROM $T t ";
    if ($hasCdata) {
        $sqlFr .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }
    $sqlFr .= " LEFT JOIN $PR pr ON pr.priority_id = " . ($priCol ?? 'NULL') . " "
         . reports_first_response_join()
         . " WHERE fr.first_response_at IS NOT NULL ";
    $sqlFr .= reports_range('fr.first_response_at', $from, $to, $typesFr, $paramsFr);
    $sqlFr .= " GROUP BY pr.priority_id";
    $frByPriority = [];
    foreach (db_rows($sqlFr, $typesFr, $paramsFr) as $r) {
        $frByPriority[$r['priority_id']] = $r['avg_first_response_seconds'];
    }

    foreach ($rows as &$r) {
        $r['avg_first_response_seconds'] = $frByPriority[$r['priority_id']] ?? null;
    }
    unset($r);

    usort($rows, function ($a, $b) { return ($b['total'] ?? 0) <=> ($a['total'] ?? 0); });

    return $rows;
}

/** Wg działu: liczba i średni czas rozwiązania (patrz $basis przy report_closed_summary). */
function report_closed_by_department(?string $from, ?string $to, string $basis = 'closed'): array
{
    $T = tbl('ticket');
    $D = tbl('department');

    $types = '';
    $params = [];

    if ($basis === 'created') {
        $sql = "SELECT
                    d.id AS dept_id,
                    COALESCE(d.name, '(bez działu)') AS department,
                    COUNT(*) AS total,
                    SUM(CASE WHEN t.closed IS NOT NULL THEN 1 ELSE 0 END) AS total_closed,
                    SUM(CASE WHEN t.closed IS NULL THEN 1 ELSE 0 END) AS still_open,
                    AVG(CASE WHEN t.closed IS NOT NULL THEN " . reports_resolution_seconds_agg_expr() . " END) AS avg_resolution_seconds
                FROM $T t
                LEFT JOIN $D d ON d.id = t.dept_id
                WHERE 1=1 ";
        $sql .= reports_range('t.created', $from, $to, $types, $params);
        $sql .= ' GROUP BY d.id ORDER BY total DESC';
        return db_rows($sql, $types, $params);
    }

    $sql = "SELECT
                d.id AS dept_id,
                COALESCE(d.name, '(bez działu)') AS department,
                COUNT(*) AS total,
                AVG(" . reports_resolution_seconds_agg_expr() . ") AS avg_resolution_seconds
            FROM $T t
            LEFT JOIN $D d ON d.id = t.dept_id
            WHERE t.closed IS NOT NULL ";
    $sql .= reports_range('t.closed', $from, $to, $types, $params);
    $sql .= " GROUP BY d.id ORDER BY total DESC";

    return db_rows($sql, $types, $params);
}

/** W czasie — wg dnia zamknięcia albo wg dnia zgłoszenia (patrz $basis przy report_closed_summary). */
function report_closed_over_time(?string $from, ?string $to, string $basis = 'closed'): array
{
    $T = tbl('ticket');

    $types = '';
    $params = [];

    if ($basis === 'created') {
        $sql = "SELECT DATE(t.created) AS day, COUNT(*) AS closed
                FROM $T t WHERE 1=1 ";
        $sql .= reports_range('t.created', $from, $to, $types, $params);
        $sql .= ' GROUP BY DATE(t.created) ORDER BY day ASC';
        return db_rows($sql, $types, $params);
    }

    $sql = "SELECT DATE(t.closed) AS day, COUNT(*) AS closed
            FROM $T t
            WHERE t.closed IS NOT NULL ";
    $sql .= reports_range('t.closed', $from, $to, $types, $params);
    $sql .= " GROUP BY DATE(t.closed) ORDER BY day ASC";

    return db_rows($sql, $types, $params);
}

/** Przegląd do górnych KPI: otwarte teraz + skrót zamkniętych w okresie. */
function report_overview(?string $from, ?string $to, string $basis = 'closed'): array
{
    $T = tbl('ticket');
    $S = tbl('ticket_status');

    $openRow = db_row(
        "SELECT COUNT(*) AS c FROM $T t
         JOIN $S s ON s.id = t.status_id AND s.state = 'open'"
    );
    $closed = report_closed_summary($from, $to, $basis);

    return [
        'open_now'                   => $openRow ? (int) $openRow['c'] : 0,
        'closed_in_range'            => $closed['total_closed'] ?? 0,
        'avg_first_response_seconds' => $closed['avg_first_response_seconds'] ?? null,
        'avg_resolution_seconds'     => $closed['avg_resolution_seconds'] ?? null,
    ];
}

/** Zbiorcza analityka zamkniętych ticketów (jeden endpoint dla frontu). */
function report_closed_analytics(?string $from, ?string $to, string $basis = 'closed'): array
{
    return [
        'summary'       => report_closed_summary($from, $to, $basis),
        'by_priority'   => report_closed_by_priority($from, $to, $basis),
        'by_department' => report_closed_by_department($from, $to, $basis),
        'over_time'     => report_closed_over_time($from, $to, $basis),
        'basis'         => $basis,
    ];
}

/**
 * Kohorta wg daty UTWORZENIA ticketa (nie zamknięcia!) z rozbiciem na
 * BIEŻĄCY status — dokładnie tak, jak liczy natywny raport „Statystyki"
 * w panelu admina osTicketa (tam zakres dat filtruje datę zgłoszenia, a
 * kolumny Otwarte/Przypisane/Przedawnione/Zamknięte/Usunięte to bieżący
 * stan ticketów z tej kohorty — NIE zdarzenia z tego okresu). To inny
 * przekrój niż reszta tego panelu (który filtruje po dacie ZAMKNIĘCIA),
 * dlatego liczby się różnią — to raport do porównania 1:1 z osTicketem,
 * a nie zamiennik pozostałych raportów.
 *
 * „Ponownie otwarte" i „Usunięte" wymagają danych, których nie da się
 * niezawodnie wyliczyć z samej tabeli ost_ticket (usunięty ticket zwykle
 * znika z tabeli całkowicie, a licznik ponownych otwarć bywa liczony przez
 * wtyczki raportowe z osobnego źródła) — zwracamy je tylko, gdy uda się
 * je wykryć w schemacie; w przeciwnym razie front pokazuje „niedostępne".
 */
function report_created_cohort(?string $from, ?string $to, array $deptIds = []): array
{
    $T = tbl('ticket');
    $S = tbl('ticket_status');
    $D = tbl('department');

    $hasOverdue = db_column_exists($T, 'isoverdue');

    $deletedStatusId = null;
    foreach (schema_statuses() as $st) {
        $hay = mb_strtolower(((string) ($st['state'] ?? '')) . ' ' . ((string) ($st['name'] ?? '')));
        if (preg_match('/delet|usuni|archiv/u', $hay)) {
            $deletedStatusId = (int) $st['id'];
            break;
        }
    }

    $types = '';
    $params = [];

    $overdueExpr = $hasOverdue ? 'SUM(CASE WHEN t.isoverdue = 1 THEN 1 ELSE 0 END)' : 'NULL';
    $deletedExpr = $deletedStatusId !== null
        ? 'SUM(CASE WHEN t.status_id = ' . $deletedStatusId . ' THEN 1 ELSE 0 END)'
        : 'NULL';

    $sql = "SELECT
                d.id AS dept_id,
                COALESCE(d.name, '(bez działu)') AS department,
                COUNT(*) AS created_total,
                SUM(CASE WHEN s.state = 'open' THEN 1 ELSE 0 END) AS currently_open,
                SUM(CASE WHEN s.state = 'open' AND t.staff_id > 0 THEN 1 ELSE 0 END) AS currently_assigned,
                $overdueExpr AS currently_overdue,
                SUM(CASE WHEN s.state = 'closed' THEN 1 ELSE 0 END) AS currently_closed,
                $deletedExpr AS currently_deleted,
                AVG(CASE WHEN s.state = 'closed'
                         THEN " . reports_resolution_seconds_agg_expr() . " END) AS avg_resolution_seconds,
                AVG(CASE WHEN fr.first_response_at IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) END) AS avg_first_response_seconds
            FROM $T t
            LEFT JOIN $D d ON d.id = t.dept_id
            JOIN $S s ON s.id = t.status_id "
         . reports_first_response_join()
         . ' WHERE 1=1 ';
    $sql .= reports_range('t.created', $from, $to, $types, $params);
    if (!empty($deptIds)) {
        $place = implode(',', array_fill(0, count($deptIds), '?'));
        $sql .= " AND t.dept_id IN ($place) ";
        foreach ($deptIds as $id) { $types .= 'i'; $params[] = (int) $id; }
    }
    $sql .= ' GROUP BY d.id ORDER BY created_total DESC';

    return [
        'rows'        => db_rows($sql, $types, $params),
        'has_overdue' => $hasOverdue,
        'has_deleted' => $deletedStatusId !== null,
    ];
}

// ---------------------------------------------------------------------------
// Przekrój wg wymiaru (pracownicy / użytkownicy / zespoły / oddziały)
// z kolumnami wg priorytetu. Dotyczy ticketów ZAMKNIĘTYCH w zakresie dat.
// ---------------------------------------------------------------------------

/** Wyrażenie SQL dla metryki (wartość w komórce). */
function reports_metric_expr(string $metric): string
{
    switch ($metric) {
        case 'sum_resolution':
            return 'SUM(' . reports_resolution_seconds_agg_expr() . ')';
        case 'avg_first_response':
            return 'AVG(CASE WHEN fr.first_response_at IS NOT NULL
                            THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) END)';
        case 'count':
            return 'COUNT(*)';
        case 'avg_resolution':
        default:
            return 'AVG(' . reports_resolution_seconds_agg_expr() . ')';
    }
}

/** Czy metryka to liczba sekund (do formatowania jako czas), czy zwykła liczba. */
function reports_metric_is_time(string $metric): bool
{
    return $metric !== 'count';
}

/**
 * @param string     $dimension staff|user|team|dept
 * @param string     $metric    avg_resolution|sum_resolution|avg_first_response|count
 * @param int[]      $deptIds   filtr działów (tylko dla staff); pusty = wszystkie
 * @param bool       $activeOnly tylko aktywne konta (tylko dla staff)
 */
function report_breakdown(string $dimension, string $metric, ?string $from, ?string $to,
                          array $deptIds = [], bool $activeOnly = true): array
{
    $ps       = schema_priority_source();
    $priCol   = reports_priority_expr($ps);
    $hasCdata = $ps !== null && $ps['joinOnTicket'];

    $T  = tbl('ticket');
    $PR = tbl('ticket_priority');
    $CD = tbl('ticket__cdata');

    // Definicja wymiaru: pola encji, złączenie, warunek "istnieje".
    switch ($dimension) {
        case 'user':
            $entId = 'u.id'; $entName = 'u.name';
            $join  = ' LEFT JOIN ' . tbl('user') . ' u ON u.id = t.user_id ';
            $exists = ' AND t.user_id > 0 ';
            break;
        case 'team':
            $entId = 'tm.team_id'; $entName = 'tm.name';
            $join  = ' LEFT JOIN ' . tbl('team') . ' tm ON tm.team_id = t.team_id ';
            $exists = ' AND t.team_id > 0 ';
            break;
        case 'dept':
            $entId = 'd.id'; $entName = "COALESCE(d.name,'(bez działu)')";
            $join  = ' LEFT JOIN ' . tbl('department') . ' d ON d.id = t.dept_id ';
            $exists = ' AND t.dept_id > 0 ';
            break;
        case 'staff':
        default:
            $dimension = 'staff';
            $entId = 'st.staff_id';
            $entName = "TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,'')))";
            $join  = ' LEFT JOIN ' . tbl('staff') . ' st ON st.staff_id = t.staff_id ';
            $exists = ' AND t.staff_id > 0 ';
            break;
    }

    $valueExpr = reports_metric_expr($metric);
    $needFr    = ($metric === 'avg_first_response');

    $types = '';
    $params = [];

    $sql = "SELECT
                $entId   AS entity_id,
                $entName AS entity_name,
                pr.priority_id,
                pr.priority_desc AS priority_name,
                pr.priority_color,
                pr.priority_urgency,
                $valueExpr AS value,
                COUNT(*)   AS cnt
            FROM $T t ";
    if ($hasCdata) {
        $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }
    $sql .= " LEFT JOIN $PR pr ON pr.priority_id = " . ($priCol ?? 'NULL') . " ";
    $sql .= $join;
    if ($needFr) {
        $sql .= reports_first_response_join();
    }
    // Pierwsza odpowiedź zakotwiczona jest na dacie samej ODPOWIEDZI, nie
    // zamknięcia — ticket, który dostał odpowiedź w sierpniu, ale zamknął się
    // dopiero we wrześniu, ma liczyć się do sierpnia (patrz komentarz przy
    // report_closed_summary). Dlatego dla tej metryki NIE wymagamy
    // t.closed IS NOT NULL — ticket może być nadal otwarty.
    $sql .= $needFr
        ? " WHERE fr.first_response_at IS NOT NULL " . $exists
        : " WHERE t.closed IS NOT NULL " . $exists;

    // Filtr działów + aktywności — tylko dla pracowników.
    if ($dimension === 'staff') {
        if (!empty($deptIds)) {
            $place = implode(',', array_fill(0, count($deptIds), '?'));
            $sql .= " AND st.dept_id IN ($place) ";
            foreach ($deptIds as $id) { $types .= 'i'; $params[] = (int) $id; }
        }
        if ($activeOnly) {
            $sql .= ' AND st.isactive = 1 ';
        }
    }

    $sql .= $needFr
        ? reports_range('fr.first_response_at', $from, $to, $types, $params)
        : reports_range('t.closed', $from, $to, $types, $params);
    $sql .= " GROUP BY entity_id, pr.priority_id
              HAVING entity_id IS NOT NULL
              ORDER BY entity_name ASC, pr.priority_urgency DESC";

    return db_rows($sql, $types, $params);
}

/**
 * Priorytetowe, zamknięte tickety per agent — surowe wiersze do „drążenia".
 * Front agreguje je na agentów, liczy średnie/maksima i wskazuje winny ticket.
 *
 * @param int[]  $priorityIds  które priorytety liczymy (puste = wszystkie)
 * @param int[]  $deptIds      filtr działów agenta (puste = wszystkie)
 * @param bool   $activeOnly   tylko aktywne konta agentów
 */
function report_priority_tickets(array $priorityIds, ?string $from, ?string $to,
                                 array $deptIds = [], bool $activeOnly = false, int $limit = 6000): array
{
    $ps     = schema_priority_source();
    $priCol = reports_priority_expr($ps);
    if ($priCol === null) {
        return [];
    }
    $hasCdata = $ps['joinOnTicket'];

    $T  = tbl('ticket');
    $PR = tbl('ticket_priority');
    $CD = tbl('ticket__cdata');
    $U  = tbl('user');
    $ST = tbl('staff');

    $types = '';
    $params = [];

    $sql = "SELECT
                t.number,
                st.staff_id,
                TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,''))) AS agent,
                st.isactive,
                " . ($hasCdata ? 'cd.subject' : 'NULL') . " AS subject,
                u.name AS submitter,
                t.created AS opened_at,
                t.closed  AS closed_at,
                pr.priority_desc AS priority_name,
                CASE WHEN fr.first_response_at IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) ELSE NULL END AS first_response_seconds,
                TIMESTAMPDIFF(SECOND, t.created, t.closed) AS resolution_seconds,
                " . reports_resolution_outlier_expr() . " AS resolution_is_outlier
            FROM $T t ";
    if ($hasCdata) {
        $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }
    $sql .= " LEFT JOIN $PR pr ON pr.priority_id = $priCol
              LEFT JOIN $U u   ON u.id = t.user_id
              LEFT JOIN $ST st ON st.staff_id = t.staff_id "
         . reports_first_response_join()
         . " WHERE t.closed IS NOT NULL AND t.staff_id > 0 ";

    if (!empty($priorityIds)) {
        $place = implode(',', array_fill(0, count($priorityIds), '?'));
        $sql .= " AND $priCol IN ($place) ";
        foreach ($priorityIds as $id) { $types .= 'i'; $params[] = (int) $id; }
    }
    if (!empty($deptIds)) {
        $place = implode(',', array_fill(0, count($deptIds), '?'));
        $sql .= " AND st.dept_id IN ($place) ";
        foreach ($deptIds as $id) { $types .= 'i'; $params[] = (int) $id; }
    }
    if ($activeOnly) {
        $sql .= ' AND st.isactive = 1 ';
    }
    $sql .= reports_range('t.closed', $from, $to, $types, $params);

    $limit = max(1, min($limit, 20000));
    $sql  .= ' ORDER BY resolution_seconds DESC LIMIT ' . $limit;

    return db_rows($sql, $types, $params);
}

/**
 * Ranking pracowników na priorytetowych zgłoszeniach: dla każdego agenta liczy
 * średni czas 1. odpowiedzi i średni czas rozwiązania, a do KAŻDEJ z tych
 * średnich wskazuje konkretny ticket, który miał najdłuższy czas — żeby było
 * widać na pierwszy rzut oka, czy średnią zawyżył jeden odstający przypadek,
 * czy to systemowy problem agenta.
 *
 * Zwraca ['summary' => [...ranking...], 'tickets' => [...surowe wiersze...]]
 * — 'tickets' służy do rozwinięcia pełnej listy danego agenta na froncie.
 */
function report_priority_ranking(array $priorityIds, ?string $from, ?string $to,
                                  array $deptIds = [], bool $activeOnly = false): array
{
    $rows = report_priority_tickets($priorityIds, $from, $to, $deptIds, $activeOnly);

    $agents = [];
    foreach ($rows as $r) {
        $id = (int) $r['staff_id'];
        if (!isset($agents[$id])) {
            $agents[$id] = [
                'staff_id' => $id,
                'agent'    => $r['agent'] !== '' ? $r['agent'] : '(bez nazwy)',
                'isactive' => (int) $r['isactive'],
                'count'    => 0,
                'fr_sum'   => 0, 'fr_count'  => 0, 'fr_max'  => null, 'fr_max_ticket'  => null,
                'res_sum'  => 0, 'res_count' => 0, 'res_max' => null, 'res_max_ticket' => null,
            ];
        }
        $a = &$agents[$id];
        $a['count']++;

        if ($r['first_response_seconds'] !== null) {
            $sec = (int) $r['first_response_seconds'];
            $a['fr_sum'] += $sec;
            $a['fr_count']++;
            if ($a['fr_max'] === null || $sec > $a['fr_max']) {
                $a['fr_max'] = $sec;
                $a['fr_max_ticket'] = [
                    'number' => $r['number'], 'subject' => $r['subject'], 'submitter' => $r['submitter'],
                    'opened_at' => $r['opened_at'], 'closed_at' => $r['closed_at'],
                    'priority_name' => $r['priority_name'], 'seconds' => $sec,
                ];
            }
        }
        // Tickety "wznowione po długiej przerwie" (patrz reports_max_resolution_days)
        // liczą się do count() i widać je w pełnej liście (tickets), ale NIE
        // wliczamy ich do avg/max czasu rozwiązania — inaczej jeden ticket
        // sprzed lat potrafiłby zawyżyć średnią agenta o miesiące.
        if ($r['resolution_seconds'] !== null && empty($r['resolution_is_outlier'])) {
            $sec = (int) $r['resolution_seconds'];
            $a['res_sum'] += $sec;
            $a['res_count']++;
            if ($a['res_max'] === null || $sec > $a['res_max']) {
                $a['res_max'] = $sec;
                $a['res_max_ticket'] = [
                    'number' => $r['number'], 'subject' => $r['subject'], 'submitter' => $r['submitter'],
                    'opened_at' => $r['opened_at'], 'closed_at' => $r['closed_at'],
                    'priority_name' => $r['priority_name'], 'seconds' => $sec,
                ];
            }
        }
        unset($a);
    }

    $summary = [];
    foreach ($agents as $a) {
        $summary[] = [
            'staff_id' => $a['staff_id'],
            'agent'    => $a['agent'],
            'isactive' => $a['isactive'],
            'count'    => $a['count'],
            'avg_first_response_seconds' => $a['fr_count'] ? (int) round($a['fr_sum'] / $a['fr_count']) : null,
            'max_first_response_seconds' => $a['fr_max'],
            'max_first_response_ticket'  => $a['fr_max_ticket'],
            'avg_resolution_seconds'     => $a['res_count'] ? (int) round($a['res_sum'] / $a['res_count']) : null,
            'max_resolution_seconds'     => $a['res_max'],
            'max_resolution_ticket'      => $a['res_max_ticket'],
        ];
    }
    usort($summary, function ($x, $y) {
        return ($y['avg_resolution_seconds'] ?? -1) <=> ($x['avg_resolution_seconds'] ?? -1);
    });

    return ['summary' => $summary, 'tickets' => $rows];
}

// ---------------------------------------------------------------------------
// Oceny ticketów (gwiazdki 1–5, liczone ze znaków w polu cdata)
// ---------------------------------------------------------------------------

/** Wyrażenie zwracające ocenę 1–5 (albo NULL) z kolumny cdata. */
function reports_rating_expr(string $col): string
{
    // Liczba → bierzemy wprost; ciąg gwiazdek → liczymy znaki.
    return "CASE
                WHEN cd.`$col` IS NULL OR cd.`$col` = '' THEN NULL
                WHEN cd.`$col` REGEXP '^[0-9]+$' THEN CAST(cd.`$col` AS UNSIGNED)
                ELSE CHAR_LENGTH(TRIM(cd.`$col`))
            END";
}

/** Analityka ocen: podsumowanie + rozkład 1–5. */
function report_ratings(?string $from, ?string $to, array $priorityIds = []): array
{
    $field = schema_rating_field();
    if ($field === null) {
        return ['available' => false];
    }

    $ps     = schema_priority_source();
    $priCol = reports_priority_expr($ps);

    $T  = tbl('ticket');
    $CD = tbl('ticket__cdata');
    $ratingExpr = reports_rating_expr($field);

    // Podzapytanie z oceną na ticket.
    $types = '';
    $params = [];
    $inner = "SELECT $ratingExpr AS r
              FROM $T t
              LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id
              WHERE t.closed IS NOT NULL ";
    $inner .= reports_priority_filter($priCol, $priorityIds, $types, $params);
    $inner .= reports_range('t.closed', $from, $to, $types, $params);

    $summary = db_row(
        "SELECT COUNT(*) AS total_closed,
                SUM(CASE WHEN r BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS rated,
                AVG(CASE WHEN r BETWEEN 1 AND 5 THEN r END) AS avg_rating
         FROM ($inner) x",
        $types,
        $params
    );

    $distribution = db_rows(
        "SELECT r AS rating, COUNT(*) AS cnt
         FROM ($inner) x
         WHERE r BETWEEN 1 AND 5
         GROUP BY r ORDER BY r ASC",
        $types,
        $params
    );

    return [
        'available'    => true,
        'field'        => $field,
        'summary'      => $summary,
        'distribution' => $distribution,
    ];
}

/** Lista zamkniętych ticketów z konkretną oceną (do „poczytania"). */
function report_ratings_detail(int $rating, ?string $from, ?string $to, array $priorityIds = [], int $limit = 300): array
{
    $field = schema_rating_field();
    if ($field === null) {
        return [];
    }

    $ps       = schema_priority_source();
    $priCol   = reports_priority_expr($ps);
    $hasCdata = $ps !== null && $ps['joinOnTicket'];

    $T  = tbl('ticket');
    $CD = tbl('ticket__cdata');
    $U  = tbl('user');
    $ST = tbl('staff');
    $ratingExpr = reports_rating_expr($field);

    $types = 'i';
    $params = [$rating];

    $sql = "SELECT
                t.number,
                t.created  AS opened_at,
                t.closed   AS closed_at,
                " . ($hasCdata ? 'cd.subject' : 'NULL') . " AS subject,
                u.name     AS submitter,
                TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,''))) AS agent,
                fr.first_response_at,
                CASE WHEN fr.first_response_at IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) ELSE NULL END AS first_response_seconds,
                TIMESTAMPDIFF(SECOND, t.created, t.closed) AS resolution_seconds,
                " . reports_resolution_outlier_expr() . " AS resolution_is_outlier
            FROM $T t
            LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id
            LEFT JOIN $U u   ON u.id = t.user_id
            LEFT JOIN $ST st ON st.staff_id = t.staff_id "
         . reports_first_response_join()
         . " WHERE t.closed IS NOT NULL AND ($ratingExpr) = ? ";
    $sql .= reports_priority_filter($priCol, $priorityIds, $types, $params);
    $sql .= reports_range('t.closed', $from, $to, $types, $params);
    $limit = max(1, min($limit, 1000));
    $sql  .= ' ORDER BY t.closed DESC LIMIT ' . $limit;

    return db_rows($sql, $types, $params);
}

/** Średnia ocena per pracownik / zespół / dział (bez rozbicia na priorytety). */
function report_ratings_breakdown(string $dimension, ?string $from, ?string $to, array $priorityIds = []): array
{
    $field = schema_rating_field();
    if ($field === null) {
        return ['available' => false, 'data' => []];
    }

    $ps     = schema_priority_source();
    $priCol = reports_priority_expr($ps);

    $T  = tbl('ticket');
    $CD = tbl('ticket__cdata');
    $ratingExpr = reports_rating_expr($field);

    switch ($dimension) {
        case 'team':
            $entId = 'tm.team_id'; $entName = 'tm.name';
            $join  = ' LEFT JOIN ' . tbl('team') . ' tm ON tm.team_id = t.team_id ';
            $exists = ' AND t.team_id > 0 ';
            break;
        case 'dept':
            $entId = 'd.id'; $entName = "COALESCE(d.name,'(bez działu)')";
            $join  = ' LEFT JOIN ' . tbl('department') . ' d ON d.id = t.dept_id ';
            $exists = ' AND t.dept_id > 0 ';
            break;
        case 'staff':
        default:
            $dimension = 'staff';
            $entId = 'st.staff_id';
            $entName = "TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,'')))";
            $join  = ' LEFT JOIN ' . tbl('staff') . ' st ON st.staff_id = t.staff_id ';
            $exists = ' AND t.staff_id > 0 ';
            break;
    }

    $types = '';
    $params = [];

    $sql = "SELECT
                $entId AS entity_id,
                $entName AS entity_name,
                COUNT(*) AS total_closed,
                SUM(CASE WHEN ($ratingExpr) BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS rated,
                AVG(CASE WHEN ($ratingExpr) BETWEEN 1 AND 5 THEN ($ratingExpr) END) AS avg_rating
            FROM $T t
            LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id "
         . $join
         . " WHERE t.closed IS NOT NULL " . $exists;
    $sql .= reports_priority_filter($priCol, $priorityIds, $types, $params);
    $sql .= reports_range('t.closed', $from, $to, $types, $params);
    $sql .= " GROUP BY entity_id HAVING entity_id IS NOT NULL ORDER BY avg_rating ASC";

    return ['available' => true, 'data' => db_rows($sql, $types, $params)];
}

// ---------------------------------------------------------------------------
// „Smaczki": szybka odpowiedź, ale bardzo późne faktyczne zamknięcie —
// klasyczny wzorzec „podsyłam i zamykam" -> klient wraca po tygodniach/
// miesiącach. Odsiewamy to z danych, które już mamy (czas do 1. odpowiedzi
// i czas do zamknięcia), więc działa niezależnie od tego, czy baza w ogóle
// rejestruje zdarzenie "ponowne otwarcie".
// ---------------------------------------------------------------------------

/**
 * @param int $fastResponseMinutes Próg „szybkiej" pierwszej odpowiedzi (minuty) —
 *                                 ustawiany suwakiem w interfejsie.
 */
function report_quick_close_gap(int $fastResponseMinutes, ?string $from, ?string $to, array $priorityIds = [], int $limit = 300): array
{
    $ps       = schema_priority_source();
    $priCol   = reports_priority_expr($ps);
    $hasCdata = $ps !== null && $ps['joinOnTicket'];

    $T  = tbl('ticket');
    $PR = tbl('ticket_priority');
    $CD = tbl('ticket__cdata');
    $U  = tbl('user');
    $ST = tbl('staff');

    $fastSeconds   = max(1, $fastResponseMinutes) * 60;
    $minGapSeconds = 3600; // pomijamy różnice poniżej godziny — to jeszcze nie „smaczek"

    $types  = 'ii';
    $params = [$fastSeconds, $minGapSeconds];

    $sql = "SELECT
                t.number,
                " . ($hasCdata ? 'cd.subject' : 'NULL') . " AS subject,
                u.name AS submitter,
                TRIM(CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,''))) AS agent,
                t.created AS opened_at,
                fr.first_response_at,
                t.closed AS closed_at,
                pr.priority_desc AS priority_name,
                TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) AS first_response_seconds,
                TIMESTAMPDIFF(SECOND, t.created, t.closed) AS resolution_seconds,
                TIMESTAMPDIFF(SECOND, fr.first_response_at, t.closed) AS gap_seconds,
                " . reports_resolution_outlier_expr() . " AS resolution_is_outlier
            FROM $T t ";
    if ($hasCdata) {
        $sql .= " LEFT JOIN $CD cd ON cd.ticket_id = t.ticket_id ";
    }
    $sql .= " LEFT JOIN $PR pr ON pr.priority_id = " . ($priCol ?? 'NULL') . "
              LEFT JOIN $U u   ON u.id = t.user_id
              LEFT JOIN $ST st ON st.staff_id = t.staff_id "
         . reports_first_response_join()
         . " WHERE t.closed IS NOT NULL
               AND fr.first_response_at IS NOT NULL
               AND TIMESTAMPDIFF(SECOND, t.created, fr.first_response_at) <= ?
               AND TIMESTAMPDIFF(SECOND, fr.first_response_at, t.closed) >= ? ";
    $sql .= reports_priority_filter($priCol, $priorityIds, $types, $params);
    $sql .= reports_range('t.closed', $from, $to, $types, $params);
    $limit = max(1, min($limit, 2000));
    $sql  .= ' ORDER BY gap_seconds DESC LIMIT ' . $limit;

    $rows = db_rows($sql, $types, $params);

    // Bonus (best-effort): prawdziwe zdarzenia "ponowne otwarcie", jeśli baza
    // je rejestruje. Błąd tutaj NIE MOŻE zepsuć głównego wyniku powyżej.
    $reopenSrc = schema_reopen_event_source();
    if ($reopenSrc !== null && !empty($rows)) {
        try {
            $TH = tbl('thread');
            $EV = $reopenSrc['table'];
            $col = $reopenSrc['column'];
            $threadCol = $reopenSrc['thread_col'];

            $numbers = array_column($rows, 'number');
            $place = implode(',', array_fill(0, count($numbers), '?'));
            $counts = db_rows(
                "SELECT t.number, COUNT(*) AS reopen_count
                 FROM $T t
                 JOIN $TH th ON th.object_id = t.ticket_id AND th.object_type = 'T'
                 JOIN $EV ev ON ev.`$threadCol` = th.id AND ev.`$col` LIKE '%reopen%'
                 WHERE t.number IN ($place)
                 GROUP BY t.number",
                str_repeat('s', count($numbers)),
                $numbers
            );
            $byNumber = [];
            foreach ($counts as $c) { $byNumber[$c['number']] = (int) $c['reopen_count']; }
            foreach ($rows as &$r) { $r['reopen_count'] = $byNumber[$r['number']] ?? 0; }
            unset($r);
        } catch (Throwable $e) {
            foreach ($rows as &$r) { $r['reopen_count'] = null; }
            unset($r);
        }
    } else {
        foreach ($rows as &$r) { $r['reopen_count'] = null; }
        unset($r);
    }

    return ['data' => $rows, 'reopen_available' => $reopenSrc !== null];
}
