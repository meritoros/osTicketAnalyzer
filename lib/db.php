<?php
/**
 * Cienka warstwa na mysqli z zapytaniami przygotowywanymi (prepared statements).
 * Nazwy tabel budujemy z zaufanego prefiksu (funkcja tbl()).
 * Dane od użytkownika trafiają WYŁĄCZNIE przez parametry (bind), nigdy do SQL-a.
 *
 * Dane dostępowe do bazy mogą pochodzić z dwóch źródeł:
 *   - config.php            (tryb produkcyjny, db.mode = 'config')
 *   - z zapytania HTTP       (tryb testowy „hasło w okienku", db.mode = 'prompt')
 * Nic z trybu testowego nie jest nigdzie zapisywane po stronie serwera.
 */

declare(strict_types=1);

/** Bieżące dane dostępowe do bazy (override z requestu albo config). */
function db_credentials(): array
{
    if (isset($GLOBALS['OSTA_DB_OVERRIDE']) && is_array($GLOBALS['OSTA_DB_OVERRIDE'])) {
        return $GLOBALS['OSTA_DB_OVERRIDE'];
    }
    return $GLOBALS['OSTA_CONFIG']['db'] ?? [];
}

/** Ustawia dane dostępowe na czas jednego requestu (tryb 'prompt'). */
function db_set_runtime_credentials(array $creds): void
{
    $GLOBALS['OSTA_DB_OVERRIDE'] = [
        'host'    => (string) ($creds['host'] ?? 'localhost'),
        'name'    => (string) ($creds['name'] ?? ''),
        'user'    => (string) ($creds['user'] ?? ''),
        'pass'    => (string) ($creds['pass'] ?? ''),
        'socket'  => isset($creds['socket']) && $creds['socket'] !== '' ? (string) $creds['socket'] : null,
        'charset' => 'utf8mb4',
        'prefix'  => (string) ($creds['prefix'] ?? 'ost_'),
    ];
}

/** Nazwa bieżącej bazy. */
function db_name(): string
{
    return (string) (db_credentials()['name'] ?? '');
}

/** Zwraca współdzielone połączenie mysqli. */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    $c = db_credentials();

    mysqli_report(MYSQLI_REPORT_OFF); // błędy obsługujemy sami

    $conn = mysqli_init();
    $ok = @$conn->real_connect(
        $c['host'] ?? 'localhost',
        $c['user'] ?? '',
        $c['pass'] ?? '',
        $c['name'] ?? '',
        null,
        $c['socket'] ?? null
    );

    if (!$ok) {
        $conn = null;
        throw new RuntimeException('Nie można połączyć się z bazą: ' . mysqli_connect_error());
    }

    $conn->set_charset($c['charset'] ?? 'utf8mb4');
    return $conn;
}

/** Pełna nazwa tabeli osTicketa, np. tbl('ticket') => 'ost_ticket'. */
function tbl(string $name): string
{
    $prefix = (string) (db_credentials()['prefix'] ?? 'ost_');
    return $prefix . $name;
}

/**
 * Wykonuje SELECT i zwraca wszystkie wiersze jako tablicę asocjacyjną.
 *
 * @param string $sql    Zapytanie z placeholderami "?"
 * @param string $types  Typy dla bind_param, np. "sii"
 * @param array  $params Wartości parametrów
 */
function db_rows(string $sql, string $types = '', array $params = []): array
{
    $stmt = db()->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Błąd SQL (prepare): ' . db()->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Błąd SQL (execute): ' . $err);
    }
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** Jak db_rows(), ale zwraca pierwszy wiersz albo null. */
function db_row(string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_rows($sql, $types, $params);
    return $rows[0] ?? null;
}

/** Sprawdza, czy tabela istnieje w bieżącej bazie. */
function db_table_exists(string $fullName): bool
{
    $row = db_row(
        'SELECT COUNT(*) AS c FROM information_schema.tables
         WHERE table_schema = ? AND table_name = ?',
        'ss',
        [db_name(), $fullName]
    );
    return $row && (int) $row['c'] > 0;
}

/** Sprawdza, czy kolumna istnieje w tabeli. */
function db_column_exists(string $fullTable, string $column): bool
{
    $row = db_row(
        'SELECT COUNT(*) AS c FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        'sss',
        [db_name(), $fullTable, $column]
    );
    return $row && (int) $row['c'] > 0;
}
