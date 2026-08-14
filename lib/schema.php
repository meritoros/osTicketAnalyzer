<?php
/**
 * Wykrywanie i weryfikacja schematu osTicketa.
 * Dzięki temu raporty nie zakładają w ciemno układu bazy —
 * diagnostics.php korzysta z tych funkcji, by pokazać stan instalacji.
 */

declare(strict_types=1);

/** Wersja osTicketa z tabeli konfiguracyjnej (best-effort). */
function schema_osticket_version(): ?string
{
    if (!db_table_exists(tbl('config'))) {
        return null;
    }
    try {
        $row = db_row(
            'SELECT `value` FROM ' . tbl('config') . '
             WHERE `namespace` = ? AND `key` = ? LIMIT 1',
            'ss',
            ['core', 'version']
        );
        if ($row && !empty($row['value'])) {
            return (string) $row['value'];
        }
    } catch (Throwable $e) {
        // starszy układ ost_config bez namespace — pomijamy
    }
    return null;
}

/**
 * W której tabeli/kolumnie trzyma priorytet ticket?
 * Nowsze osTickety: ost_ticket__cdata.priority (priority_id).
 * Starsze: ost_ticket.priority_id.
 *
 * @return array{table:string, column:string, joinOnTicket:bool}|null
 */
function schema_priority_source(): ?array
{
    if (db_table_exists(tbl('ticket__cdata')) && db_column_exists(tbl('ticket__cdata'), 'priority')) {
        return ['table' => tbl('ticket__cdata'), 'column' => 'priority', 'joinOnTicket' => true];
    }
    if (db_column_exists(tbl('ticket'), 'priority_id')) {
        return ['table' => tbl('ticket'), 'column' => 'priority_id', 'joinOnTicket' => false];
    }
    return null;
}

/** Lista priorytetów: [['priority_id'=>..,'priority'=>'high','priority_desc'=>'High'], ...]. */
function schema_priorities(): array
{
    if (!db_table_exists(tbl('ticket_priority'))) {
        return [];
    }
    return db_rows(
        'SELECT priority_id, priority, priority_desc, priority_color, priority_urgency
         FROM ' . tbl('ticket_priority') . '
         ORDER BY priority_urgency ASC'
    );
}

/** Lista statusów: [['id'=>..,'name'=>..,'state'=>'open'|'closed'|...], ...]. */
function schema_statuses(): array
{
    if (!db_table_exists(tbl('ticket_status'))) {
        return [];
    }
    return db_rows(
        'SELECT id, name, state FROM ' . tbl('ticket_status') . ' ORDER BY id ASC'
    );
}

/**
 * Sprawdza komplet tabel potrzebnych do raportów.
 * @return array<string,bool> pełna_nazwa_tabeli => czy_istnieje
 */
function schema_required_tables(): array
{
    $needed = [
        'ticket', 'ticket_status', 'ticket_priority',
        'thread', 'thread_entry', 'user', 'staff', 'department',
    ];
    $out = [];
    foreach ($needed as $t) {
        $out[tbl($t)] = db_table_exists(tbl($t));
    }
    // cdata jest opcjonalne (zależnie od wersji)
    $out[tbl('ticket__cdata')] = db_table_exists(tbl('ticket__cdata'));
    return $out;
}

/** Lista działów: [['id'=>..,'name'=>..], ...]. */
function schema_departments(): array
{
    if (!db_table_exists(tbl('department'))) {
        return [];
    }
    return db_rows('SELECT id, name FROM ' . tbl('department') . ' ORDER BY name ASC');
}

/** Lista zespołów: [['team_id'=>..,'name'=>..], ...]. */
function schema_teams(): array
{
    if (!db_table_exists(tbl('team'))) {
        return [];
    }
    return db_rows('SELECT team_id, name FROM ' . tbl('team') . ' ORDER BY name ASC');
}

/**
 * Wykrywa kolumnę w ost_ticket__cdata z oceną ticketu (gwiazdki).
 * Kolejność: nadpisanie z configu → dopasowanie po nazwie/etykiecie pola formularza
 * → skan kolumn cdata po nazwie. Zwraca bezpieczną nazwę kolumny albo null.
 */
function schema_rating_field(): ?string
{
    $cd = tbl('ticket__cdata');
    if (!db_table_exists($cd)) {
        return null;
    }

    $safe = function ($col) use ($cd): ?string {
        $col = (string) $col;
        if ($col !== '' && preg_match('/^[A-Za-z0-9_]+$/', $col) && db_column_exists($cd, $col)) {
            return $col;
        }
        return null;
    };

    // 1) nadpisanie z configu
    $cfg = (string) cfg('rating.field', '');
    if ($cfg !== '') {
        return $safe($cfg);
    }

    // 2) po polach formularza (label/name zawiera ocena/gwiazd/rating/star)
    if (db_table_exists(tbl('form_field'))) {
        try {
            $rows = db_rows('SELECT name, label FROM ' . tbl('form_field'));
            foreach ($rows as $r) {
                $hay = mb_strtolower(((string) ($r['name'] ?? '')) . ' ' . ((string) ($r['label'] ?? '')));
                if (preg_match('/ocen|gwiazd|rating|star/u', $hay)) {
                    $found = $safe($r['name'] ?? '');
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        } catch (Throwable $e) { /* ignoruj */ }
    }

    // 3) skan kolumn cdata po nazwie
    $cols = db_rows(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ?',
        'ss',
        [db_name(), $cd]
    );
    foreach ($cols as $c) {
        $name = $c['column_name'] ?? ($c['COLUMN_NAME'] ?? '');
        if ($name !== '' && preg_match('/ocen|gwiazd|rating|star/u', mb_strtolower($name))) {
            $found = $safe($name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/** Lista pól formularzy (do diagnostyki wykrywania oceny). */
function schema_form_fields(): array
{
    if (!db_table_exists(tbl('form_field'))) {
        return [];
    }
    return db_rows(
        'SELECT name, label, type FROM ' . tbl('form_field') . ' ORDER BY name ASC'
    );
}

/** Zbiorcze podsumowanie schematu (dla API/diagnostyki). */
function schema_summary(): array
{
    $tables = schema_required_tables();
    return [
        'version'         => schema_osticket_version(),
        'prefix'          => (string) (db_credentials()['prefix'] ?? 'ost_'),
        'priority_source' => schema_priority_source(),
        'tables'          => $tables,
        'priorities'      => schema_priorities(),
        'statuses'        => schema_statuses(),
        'rating_field'    => schema_rating_field(),
        'guessed_prefix'  => empty($tables[tbl('ticket')]) ? schema_guess_prefix() : null,
    ];
}

/**
 * Jeśli prefiks z konfiguracji nie pasuje, spróbuj zgadnąć na podstawie
 * tabeli kończącej się na "ticket_status" (charakterystyczna dla osTicketa).
 */
function schema_guess_prefix(): ?string
{
    $dbName = db_name();
    $rows = db_rows(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = ? AND table_name LIKE '%ticket\\_status'",
        's',
        [$dbName]
    );
    foreach ($rows as $r) {
        $name = $r['table_name'] ?? ($r['TABLE_NAME'] ?? '');
        if ($name !== '' && substr($name, -strlen('ticket_status')) === 'ticket_status') {
            return substr($name, 0, -strlen('ticket_status'));
        }
    }
    return null;
}
