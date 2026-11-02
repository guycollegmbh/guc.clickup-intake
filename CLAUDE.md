# CLAUDE.md: guc.clickup-intake

Internes Tool der GUYCOLLE GmbH. Outlook-Mails mit der Kategorie «ClickUp» werden per Microsoft
Graph in eine Triage-Queue geholt und dort zu ClickUp-Tasks.

- Spezifikation: `docs/AGENT_PROMPT.md`
- Entscheide: `docs/01-decisions.md` (E1 bis E7, gehen der Spezifikation vor)
- Harte Regeln: `.claude/rules/intake.md`
- Arbeitsstand: `STATUS.md`

## Stack

Laravel 13, Filament 5 (Panel unter `/`, Id `app`), Livewire 4, PHP 8.4 (lokal geht 8.5), SQLite,
Redis (Queue, Cache) in Produktion, Pest, GitHub Actions.

- Theme: `resources/css/filament/app/theme.css` (Radius 0, keine Schatten über Tailwind-Tokens),
  Farbe und Schrift im `AppPanelProvider`. Source Sans 3 selbst gehostet
  (`@fontsource-variable/source-sans-3`), kein Google-Fonts-Aufruf.
- Seiten ausserhalb des Panels (Login, Setup): `resources/css/app.css`, Blade.

## Struktur

- `app/Microsoft/`: Entra-App (`MicrosoftApp`, Zugangsdaten nur aus `settings`, kein `.env`),
  Login (`MicrosoftLogin`), `GraphClient` (einziger Graph-Zugang, an ein Token gebunden),
  `Setup/` (Gerätecode, App-Registration). Token-Erneuerung je User folgt in Schritt 3, an genau
  einer Stelle.
- `app/Intake/`: `AttachmentStore` (einzige Stelle für Anhang-Dateien, `intake/{item}/{id}` auf
  Disk `local`), `Retention`. Commands `intake:prune` (Scheduler, täglich) und `intake:orphans`.
- `app/Policies/`: Items (member eigene, admin alle), Regeln (nur admin).
- `app/ClickUp/`: ab Schritt 5 ClickUp-Client und OAuth.
- `app/Models/Setting.php`: verschlüsselte Schlüssel/Wert-Ablage für Laufzeit-Konfiguration.
- `config/intake.php`: App-Konfiguration inkl. delegierter Microsoft-Scopes.

## Auth

- Nur Microsoft (Socialite `azure`, Single-Tenant). Kein Passwort. Routen in `routes/web.php`.
- Zugang steuert Entra: Enterprise-App mit «Zuweisung erforderlich», Gruppe zugewiesen.
- User per `entra_object_id`, `tid` geprüft. `role` und `active` nicht mass-assignable.
- Kein «Angemeldet bleiben», Sitzung 8 Stunden (`SESSION_LIFETIME=480`).
- Hinter Caddy: `trustProxies` für `127.0.0.1` in `bootstrap/app.php`.
- Notweg: `php artisan intake:make-admin {email}` oder `--oid=`, bricht bei Mehrdeutigkeit ab.

## Einrichtung Microsoft 365

`php artisan intake:install`: ein M365-Admin meldet sich per Gerätecode an, der Befehl legt
App-Registration, Enterprise-App (Zuweisung erforderlich) und Admin-Zustimmung an, speichert alles
verschlüsselt in `settings` und macht den Admin zum ersten App-Admin. Danach Gruppe im
Entra-Portal zuweisen. `--renew-secret`, `--manual`, `--force`. Rücksprung-Adresse aus `APP_URL`.

## Lokal

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve
vendor/bin/pest && vendor/bin/pint --test
```

Lokal ohne Redis: `QUEUE_CONNECTION=database`, `CACHE_STORE=database` (Vorgabe in `.env.example`).
Tests verbieten echte HTTP-Aufrufe (`Http::preventStrayRequests()` in `tests/Pest.php`).

## Konventionen

- UI Deutsch (Schweizer Hochdeutsch, ss statt ß, keine Gedankenstriche, keine Semikolons).
- Code, Kommentare, Commits, Branches Englisch. Conventional Commits.
- Gearbeitet wird auf `develop`. `main` bleibt bis zum ersten Deploy unberührt.
- Keine KI-Komponente in V1. Keine Kopplung zum Ticketsystem guycolle.help.

## Betrieb

vps06, `intake.guycolle.services`, Caddy, Queue-Worker als systemd-Unit, Scheduler per Cron.
Details folgen in `docs/02-setup.md` (Schritt 7).

## Agenten (`.claude/agents/`)

Werden erst nach einem Neustart der Claude-Code-Session als `subagent_type` erkannt.

Umsetzung:

- `fullstack-developer`: Code und Tests für die Schritte 1b bis 6 und Befunde. Committet nicht.
- `devops-engineer`: Commits, Pull Requests, CI, `docs/02-setup.md`. Nie direkt auf dem Server,
  Deploy nur auf ausdrückliche Anweisung.

Review (lesen nur, liefern belegte Befunde):

- `code-reviewer`: Korrektheit, Fehlerwege, Jobs, Tests. Nach jedem Schritt und vor jedem Merge.
- `security-reviewer`: Login, Policies, fremdes Mail-HTML, Anhänge, Graph, Geheimnisse.
  Nach Änderungen an diesen Bereichen und vor jedem Deploy.
- `architecture-guardian`: Spezifikation, Entscheide, Regeln, Doku-Drift, Scope.
  Nach jedem Schritt der Spezifikation und vor grösseren Änderungen.

Ablauf je Schritt: `fullstack-developer` setzt um (Tests und Pint grün), die drei Reviews laufen
parallel, `fullstack-developer` behebt Befunde mit Schweregrad kritisch und hoch,
`devops-engineer` committet, Stand in `STATUS.md`.
