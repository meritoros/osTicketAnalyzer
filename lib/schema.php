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
        'SELECT priority_id, priority, priority_desc, priority_urgency
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
        'thread', 'thread_entry', 'user', 'staff',
    ];
    $out = [];
    foreach ($needed as $t) {
        $out[tbl($t)] = db_table_exists(tbl($t));
    }
    // cdata jest opcjonalne (zależnie od wersji)
    $out[tbl('ticket__cdata')] = db_table_exists(tbl('ticket__cdata'));
    return $out;
}

/**
 * Jeśli prefiks z konfiguracji nie pasuje, spróbuj zgadnąć na podstawie
 * tabeli kończącej się na "ticket_status" (charakterystyczna dla osTicketa).
 */
function schema_guess_prefix(): ?string
{
    $dbName = (string) ($GLOBALS['OSTA_CONFIG']['db']['name'] ?? '');
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
