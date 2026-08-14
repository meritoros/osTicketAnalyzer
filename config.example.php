<?php
/**
 * SZABLON KONFIGURACJI.
 *
 * 1) Skopiuj ten plik na serwerze jako  config.php
 * 2) Uzupełnij dane bazy oraz hasło dostępu do panelu
 * 3) config.php jest w .gitignore i NIE trafia do repozytorium na GitHubie
 *
 * Możesz też podać dane przez zmienne środowiskowe (getenv) — patrz niżej.
 */

return [

    // --- Połączenie z bazą osTicketa ---------------------------------------
    'db' => [
        // Na lh.pl baza jest na localhost przez socket UNIX.
        'host'    => getenv('OSTA_DB_HOST') ?: 'localhost',

        // Nazwa bazy osTicketa (np. serwer42725_xxxxx) — sprawdź w phpMyAdmin.
        'name'    => getenv('OSTA_DB_NAME') ?: 'serwer42725_NAZWA_BAZY',

        // Użytkownik bazy.
        'user'    => getenv('OSTA_DB_USER') ?: 'serwer42725_pomoc',

        // Hasło do bazy — trzymane TYLKO tutaj (poza repo).
        'pass'    => getenv('OSTA_DB_PASS') ?: 'TWOJE_HASLO_DO_BAZY',

        // Zwykle pusto (host=localhost sam użyje socketu). W razie potrzeby:
        // '/var/run/mysqld/mysqld.sock'
        'socket'  => getenv('OSTA_DB_SOCKET') ?: null,

        'charset' => 'utf8mb4',

        // Prefiks tabel osTicketa. Standardowo 'ost_'.
        // Jeśli diagnostics.php wykryje inny — zmień tutaj.
        'prefix'  => getenv('OSTA_DB_PREFIX') ?: 'ost_',
    ],

    // --- Prosta ochrona panelu hasłem --------------------------------------
    'auth' => [
        // Hash hasła do logowania na stronę. Wygeneruj na serwerze:
        //   php -r "echo password_hash('twoje-haslo', PASSWORD_DEFAULT), PHP_EOL;"
        // Wklej wynik poniżej. PUSTE = brak logowania (NIE zostawiaj tak w internecie!).
        'password_hash' => getenv('OSTA_AUTH_HASH') ?: '',
    ],

    // --- Ustawienia aplikacji ----------------------------------------------
    'app' => [
        'title'    => 'osTicket — Statystyki',
        'timezone' => 'Europe/Warsaw',
    ],
];
