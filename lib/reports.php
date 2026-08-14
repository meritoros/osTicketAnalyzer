<?php
/**
 * Zapytania raportowe. Każda funkcja zwraca zwykłą tablicę wierszy (do JSON-a).
 * Nazwy tabel pochodzą z zaufanego prefiksu, wartości od użytkownika — tylko przez bind.
 */

declare(strict_types=1);

/** Buduje fragment WHERE dla zakresu dat po kolumnie t.created. */
function reports_date_where(?string $from, ?string $to, string &$types, array &$params): string
{
    $sql = '';
    if ($from !== null && $from !== '') {
        $sql .= ' AND t.created >= ? ';
        $types .= 's';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== null && $to !== '') {
        $sql .= ' AND t.created <= ? ';
        $types .= 's';
        $params[] = $to . ' 23:59:59';
    }
    return $sql;
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
 * RAPORT GŁÓWNY: zamknięte zgłoszenia o wybranym priorytecie
 * wraz z datą/godziną zgłoszenia, pierwszej odpowiedzi i czasem do niej.
 */
function report_high_priority_closed(int $priorityId, ?string $from, ?string $to, int $limit = 2000): array
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
                     ELSE NULL END                 AS first_response_seconds
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
              WHERE s.state = 'closed' ";

    $types  = '';
    $params = [];

    if ($priCol !== null) {
        $sql .= " AND $priCol = ? ";
        $types .= 'i';
        $params[] = $priorityId;
    }
    $sql .= reports_date_where($from, $to, $types, $params);

    $limit = max(1, min($limit, 10000));
    $sql  .= ' ORDER BY t.created DESC LIMIT ' . $limit;

    return db_rows($sql, $types, $params);
}

/** Wolumen zgłoszeń dziennie: łącznie i zamknięte. */
function report_volume(?string $from, ?string $to): array
{
    $T = tbl('ticket');
    $S = tbl('ticket_status');

    $types = '';
    $params = [];
    $sql = "SELECT DATE(t.created) AS day,
                   COUNT(*) AS total,
                   SUM(CASE WHEN s.state = 'closed' THEN 1 ELSE 0 END) AS closed
            FROM $T t
            JOIN $S s ON s.id = t.status_id
            WHERE 1=1 ";
    $sql .= reports_date_where($from, $to, $types, $params);
    $sql .= ' GROUP BY DATE(t.created) ORDER BY day ASC';

    return db_rows($sql, $types, $params);
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
    $sql = "SELECT pr.priority_id, pr.priority_desc AS priority_name, COUNT(*) AS total
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
