# osTicketAnalyzer

Panel z wynikami i statystykami zgłoszeń z **osTicketa** — dane liczy backend w PHP,
przeglądarka dostaje tylko gotowe wyniki (JSON) i rysuje z nich tabele oraz wykresy.

> **Bezpieczeństwo:** przeglądarka **nigdy** nie łączy się z bazą. Login i hasło do bazy
> siedzą wyłącznie w pliku `config.php`, który jest w `.gitignore` i **nie trafia na GitHub**.

## Jak to działa

```
Przeglądarka  ──(HTTPS, JSON)──►  api.php (PHP)  ──►  MariaDB (osTicket, localhost)
 wykresy/tabele                   liczy statystyki      hasło tylko po stronie serwera
```

Ponieważ baza na lh.pl jest na `localhost` (socket UNIX), **backend musi stać na tym
samym serwerze** co osTicket — i tam też wgrywamy ten projekt.

## Wymagania

- PHP 7.2+ z rozszerzeniem **mysqli** (na lh.pl jest PHP 7.4)
- Dostęp do bazy osTicketa (użytkownik z prawem `SELECT`)
- Apache (obsługa `.htaccess`)

## Instalacja na lh.pl (krok po kroku)

1. **Wgraj pliki** do katalogu WWW (np. `public_html/statystyki/`) — przez `git clone`
   albo FTP.

2. **Utwórz `config.php`** na serwerze (skopiuj z szablonu):

   ```bash
   cp config.example.php config.php
   ```

   i uzupełnij dane bazy (`name`, `user`, `pass`, ewentualnie `prefix`).
   Plik `config.php` jest ignorowany przez gita — zostaje tylko na serwerze.

3. **Ustaw hasło do panelu** (żeby raport nie był publiczny). Wygeneruj hash:

   ```bash
   php -r "echo password_hash('twoje-haslo', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Wklej wynik do `config.php` w `auth.password_hash`.

4. **Sprawdź instalację** — otwórz w przeglądarce `.../diagnostics.php`.
   Strona zweryfikuje połączenie, prefiks tabel, wersję osTicketa oraz wypisze
   dostępne priorytety i statusy. Gdyby prefiks był inny niż `ost_`, powie jaki ustawić.

5. **Wejdź na `index.php`** — dashboard z raportami.

## Co pokazuje panel

- **Raport główny (dla kierownika):** zamknięte zgłoszenia o wybranym priorytecie
  (domyślnie *High*) z datą i godziną zgłoszenia, datą i godziną **pierwszej odpowiedzi**
  oraz policzonym **czasem do pierwszej odpowiedzi**. Eksport do CSV (otwiera się w Excelu).
- Wolumen zgłoszeń w czasie (wszystkie / zamknięte)
- Rozkład zgłoszeń wg priorytetu
- Kto obsługuje najwięcej ticketów (agenci)
- Kto zgłasza najwięcej ticketów

Wszystko z filtrem zakresu dat.

## Skąd biorą się dane (tabele osTicketa)

| Co | Źródło |
|---|---|
| Zgłoszenie / zamknięcie | `ost_ticket.created` / `ost_ticket.closed` |
| Status „zamknięte" | `ost_ticket_status.state = 'closed'` |
| Priorytet | `ost_ticket__cdata.priority` → `ost_ticket_priority` |
| Pierwsza odpowiedź | najwcześniejszy wpis agenta (`type='R'`) w `ost_thread_entry` |
| Zgłaszający / agent | `ost_user` / `ost_staff` |

## Uwagi

- **Czas pierwszej odpowiedzi** liczony jest jako pierwsza odpowiedź agenta (człowieka)
  w wątku. Automatyczne auto-odpowiedzi są pomijane (`staff_id > 0`). To założenie łatwo
  zmienić w `lib/reports.php`.
- Wykresy używają biblioteki **Chart.js** ładowanej z CDN. Jeśli chcesz działać całkowicie
  offline/bez CDN, można ją zwendorować lokalnie — do zrobienia w przyszłości.
- Projekt jest pomyślany rozwojowo — kolejne raporty dodaje się jako funkcję w
  `lib/reports.php` i `case` w `api.php`.

## Struktura projektu

```
config.example.php   # szablon konfiguracji (config.php tworzysz na serwerze)
index.php            # dashboard (HTML + wykresy)
api.php              # API JSON (liczy raporty)
diagnostics.php      # sprawdzenie połączenia i schematu bazy
login.php / logout.php
lib/                 # backend: db, schema, auth, reports, bootstrap
assets/              # app.js, styles.css
.htaccess            # ochrona plików wrażliwych
```
