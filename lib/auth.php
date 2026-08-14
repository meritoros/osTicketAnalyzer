<?php
/**
 * Prosta ochrona panelu jednym hasłem (hash w config.php).
 * To NIE jest system kont — raport z osTicketa bywa wrażliwy,
 * więc chodzi o zablokowanie przypadkowego dostępu z internetu.
 */

declare(strict_types=1);

/** Czy logowanie jest w ogóle włączone (ustawiony hash). */
function auth_enabled(): bool
{
    return trim((string) cfg('auth.password_hash', '')) !== '';
}

/** Czy bieżąca sesja jest zalogowana. */
function auth_is_logged_in(): bool
{
    if (!auth_enabled()) {
        return true; // brak hasła w configu = otwarte (patrz ostrzeżenie w diagnostics)
    }
    return !empty($_SESSION['osta_auth']);
}

/** Weryfikuje hasło i loguje. Zwraca true przy sukcesie. */
function auth_attempt_login(string $password): bool
{
    $hash = (string) cfg('auth.password_hash', '');
    if ($hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['osta_auth'] = true;
        return true;
    }
    return false;
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/** Wymusza logowanie dla stron HTML — przekierowuje na login.php. */
function auth_require_page(): void
{
    if (!auth_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/** Wymusza logowanie dla API — zwraca 401 JSON. */
function auth_require_api(): void
{
    if (!auth_is_logged_in()) {
        json_response(['error' => 'Wymagane logowanie.'], 401);
    }
}
