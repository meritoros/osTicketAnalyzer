<?php
/**
 * Wspólny start dla każdego wejścia (index.php, api.php, diagnostics.php).
 * Ładuje konfigurację, ustawia strefę czasu, sesję i obsługę błędów.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 70200) {
    http_response_code(500);
    exit('Wymagane PHP 7.2+ (na serwerze jest ' . PHP_VERSION . ').');
}

define('OSTA_ROOT', dirname(__DIR__));

// --- Konfiguracja ----------------------------------------------------------
$configFile = OSTA_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit(
        '<h1>Brak pliku config.php</h1>' .
        '<p>Skopiuj <code>config.example.php</code> jako <code>config.php</code> ' .
        'i uzupełnij dane do bazy.</p>'
    );
}

/** @var array $CONFIG */
$CONFIG = require $configFile;
$GLOBALS['OSTA_CONFIG'] = $CONFIG;

// --- Strefa czasu ----------------------------------------------------------
date_default_timezone_set($CONFIG['app']['timezone'] ?? 'Europe/Warsaw');

// --- Błędy: loguj, nie pokazuj użytkownikowi -------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- Sesja (dla logowania) -------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require OSTA_ROOT . '/lib/db.php';
require OSTA_ROOT . '/lib/schema.php';
require OSTA_ROOT . '/lib/auth.php';
require OSTA_ROOT . '/lib/reports.php';

/** Skrót do wartości konfiguracji. */
function cfg(string $path, $default = null)
{
    $parts = explode('.', $path);
    $node  = $GLOBALS['OSTA_CONFIG'];
    foreach ($parts as $p) {
        if (!is_array($node) || !array_key_exists($p, $node)) {
            return $default;
        }
        $node = $node[$p];
    }
    return $node;
}

/** Zwraca odpowiedź JSON i kończy działanie. */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
