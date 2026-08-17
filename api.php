<?php
/**
 * API JSON. Przeglądarka pobiera stąd gotowe, policzone dane —
 * nigdy nie łączy się z bazą bezpośrednio.
 *
 * Tryb 'config'  : dane do bazy z config.php.
 * Tryb 'prompt'  : dane do bazy przychodzą w body zapytania (POST JSON, klucz "db")
 *                  i są używane tylko na czas tego żądania — nic nie jest zapisywane.
 *
 * Przykład (GET, tryb config):
 *   api.php?report=high_priority_closed&priority_id=3&from=2026-01-01&to=2026-08-14
 */

require __DIR__ . '/lib/bootstrap.php';

auth_require_api();

// --- Wejście: GET (params w query) lub POST JSON (params + ewentualne db) ---
$body = [];
$raw  = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$in = function (string $key, $default = null) use ($body) {
    if (array_key_exists($key, $body) && $body[$key] !== '') {
        return $body[$key];
    }
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        return $_GET[$key];
    }
    return $default;
};

/** Wyciąga tablicę liczb całkowitych z body (tablica) albo GET (klucz[]=..). */
$inIntArray = function (string $key) use ($body) {
    $raw = $body[$key] ?? ($_GET[$key] ?? []);
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $v) {
            if (is_numeric($v)) { $out[] = (int) $v; }
        }
    }
    return $out;
};

// --- Tryb 'prompt': użyj danych do bazy przysłanych z okienka -----------------
$mode = (string) cfg('db.mode', 'config');
if ($mode === 'prompt') {
    if (!empty($body['db']) && is_array($body['db'])) {
        db_set_runtime_credentials($body['db']);
    } else {
        json_response(['error' => 'Brak danych do bazy (tryb testowy). Wpisz je w okienku.'], 400);
    }
}
// W trybie 'config' ewentualne "db" z requestu jest IGNOROWANE (bezpieczeństwo).

$report = (string) $in('report', '');
$from   = $in('from');
$to     = $in('to');
$from   = $from !== null ? (string) $from : null;
$to     = $to   !== null ? (string) $to   : null;

foreach (['from' => $from, 'to' => $to] as $val) {
    if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        json_response(['error' => 'Nieprawidłowy format daty (oczekiwano YYYY-MM-DD).'], 400);
    }
}

try {
    switch ($report) {
        case 'diag': // test połączenia + podsumowanie schematu (dla okienka)
            json_response(['data' => schema_summary()]);
            break;

        case 'priorities':
            json_response(['data' => schema_priorities()]);
            break;

        case 'high_priority_closed':
            $priorityIds = $inIntArray('priority_ids');
            json_response(['data' => report_high_priority_closed($priorityIds, $from, $to)]);
            break;

        case 'volume':
            json_response(['data' => report_volume($from, $to)]);
            break;

        case 'by_agent':
            $limit = (int) $in('limit', 50);
            json_response(['data' => report_by_agent($from, $to, max(1, min($limit, 500)))]);
            break;

        case 'by_submitter':
            $limit = (int) $in('limit', 50);
            json_response(['data' => report_by_submitter($from, $to, max(1, min($limit, 500)))]);
            break;

        case 'by_priority':
            json_response(['data' => report_by_priority($from, $to)]);
            break;

        case 'staff_by_department':
            json_response(['data' => report_staff_by_department()]);
            break;

        case 'overview':
            json_response(['data' => report_overview($from, $to)]);
            break;

        case 'closed_analytics':
            json_response(['data' => report_closed_analytics($from, $to)]);
            break;

        case 'departments':
            json_response(['data' => schema_departments()]);
            break;

        case 'teams':
            json_response(['data' => schema_teams()]);
            break;

        case 'breakdown':
            $dimension = (string) $in('dimension', 'staff');
            if (!in_array($dimension, ['staff', 'user', 'team', 'dept'], true)) {
                $dimension = 'staff';
            }
            $metric = (string) $in('metric', 'avg_resolution');
            if (!in_array($metric, ['avg_resolution', 'sum_resolution', 'avg_first_response', 'count'], true)) {
                $metric = 'avg_resolution';
            }
            $deptIds = $inIntArray('dept_ids');
            $activeOnly = (string) $in('active_only', '1') !== '0';
            json_response([
                'data' => report_breakdown($dimension, $metric, $from, $to, $deptIds, $activeOnly),
                'meta' => ['dimension' => $dimension, 'metric' => $metric, 'is_time' => reports_metric_is_time($metric)],
            ]);
            break;

        case 'priority_ranking':
            $priorityIds = $inIntArray('priority_ids');
            $deptIds     = $inIntArray('dept_ids');
            $activeOnly  = (string) $in('active_only', '0') !== '0';
            json_response(['data' => report_priority_ranking($priorityIds, $from, $to, $deptIds, $activeOnly)]);
            break;

        case 'ratings':
            json_response(['data' => report_ratings($from, $to, $inIntArray('priority_ids'))]);
            break;

        case 'ratings_detail':
            $rating = (int) $in('rating', 0);
            if ($rating < 1 || $rating > 5) {
                json_response(['error' => 'Ocena musi być w zakresie 1–5.'], 400);
            }
            json_response(['data' => report_ratings_detail($rating, $from, $to, $inIntArray('priority_ids'))]);
            break;

        case 'ratings_breakdown':
            $dimension = (string) $in('dimension', 'staff');
            if (!in_array($dimension, ['staff', 'team', 'dept'], true)) {
                $dimension = 'staff';
            }
            json_response(report_ratings_breakdown($dimension, $from, $to, $inIntArray('priority_ids')));
            break;

        case 'quick_close_gap':
            $fastMinutes = (int) $in('fast_minutes', 15);
            if ($fastMinutes < 1) { $fastMinutes = 1; }
            if ($fastMinutes > 1440) { $fastMinutes = 1440; } // max 24h jako „szybka" odpowiedź
            json_response(report_quick_close_gap($fastMinutes, $from, $to, $inIntArray('priority_ids')));
            break;

        default:
            json_response(['error' => 'Nieznany raport.'], 404);
    }
} catch (Throwable $e) {
    // W trybie testowym pokaż powód (np. złe hasło); w produkcji tylko log.
    $msg = ($mode === 'prompt')
        ? $e->getMessage()
        : 'Błąd serwera podczas liczenia raportu.';
    error_log('[osTicketAnalyzer] ' . $e->getMessage());
    json_response(['error' => $msg], 500);
}
