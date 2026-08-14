<?php
/**
 * API JSON. Przeglądarka pobiera stąd gotowe, policzone dane —
 * nigdy nie łączy się z bazą bezpośrednio.
 *
 * Przykład: api.php?report=high_priority_closed&priority_id=3&from=2026-01-01&to=2026-08-14
 */

require __DIR__ . '/lib/bootstrap.php';

auth_require_api();

$report = isset($_GET['report']) ? (string) $_GET['report'] : '';
$from   = isset($_GET['from']) && $_GET['from'] !== '' ? (string) $_GET['from'] : null;
$to     = isset($_GET['to'])   && $_GET['to']   !== '' ? (string) $_GET['to']   : null;

// Walidacja dat: dopuszczamy tylko format YYYY-MM-DD.
foreach (['from' => $from, 'to' => $to] as $val) {
    if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        json_response(['error' => 'Nieprawidłowy format daty (oczekiwano YYYY-MM-DD).'], 400);
    }
}

try {
    switch ($report) {
        case 'priorities':
            json_response(['data' => schema_priorities()]);
            break;

        case 'high_priority_closed':
            $priorityId = isset($_GET['priority_id']) ? (int) $_GET['priority_id'] : 0;
            if ($priorityId <= 0) {
                json_response(['error' => 'Podaj priority_id (patrz report=priorities).'], 400);
            }
            json_response(['data' => report_high_priority_closed($priorityId, $from, $to)]);
            break;

        case 'volume':
            json_response(['data' => report_volume($from, $to)]);
            break;

        case 'by_agent':
            json_response(['data' => report_by_agent($from, $to)]);
            break;

        case 'by_submitter':
            json_response(['data' => report_by_submitter($from, $to)]);
            break;

        case 'by_priority':
            json_response(['data' => report_by_priority($from, $to)]);
            break;

        default:
            json_response(['error' => 'Nieznany raport.'], 404);
    }
} catch (Throwable $e) {
    error_log('[osTicketAnalyzer] ' . $e->getMessage());
    json_response(['error' => 'Błąd serwera podczas liczenia raportu.'], 500);
}
