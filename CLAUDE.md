# CLAUDE.md: guc.clickup-intake

Internes Tool der GUYCOLLE GmbH. Outlook-Mails mit der Kategorie «ClickUp» werden per Microsoft
Graph in eine Triage-Queue geholt und dort zu ClickUp-Tasks.

- Spezifikation: `docs/AGENT_PROMPT.md`
- Entscheide: `docs/01-decisions.md` (E1 bis E9, gehen der Spezifikation vor)
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
  Login (`MicrosoftLogin`), `GraphClient` (einziger Graph-Zugang, an ein Token gebunden,
  `stream()` für Anhänge), `UserTokens` (einzige Token-Erneuerung je User, `graphFor()`),
  `OutlookCategories`, `Setup/` (Gerätecode, App-Registration).
- `app/Intake/`: `MessageImporter` (Import und Dedupe nach E7 und E8), `MailboxPoller`,
  `ItemTransitions` (einzige Stelle für Statuswechsel, bedingte Updates), `AttachmentStore`
  (einzige Stelle für Anhang-Dateien, `intake/{item}/{id}` auf Disk `local`), `Retention`,
  `TriageActions` (Task erstellen, erneut hochladen, Verwerfen, Weiterleiten, prüft die Policy
  selbst, Sperre je Item), `TaskUploads` (Anhänge und Kommentar nach ClickUp), `RuleMatcher`,
  `TaskName`, `MailMarkdown` mit `SafeLinkConverter`.
- `app/Jobs/`: `PollMailbox` (eindeutig je User), `SetupOutlookCategories` (nach Login),
  `SyncOutlookCategory` (eindeutig je Item, nach Bulk-Verwerfen), `UploadTaskAttachments`
  (eindeutig je Item). Payload nur IDs.
- Commands: `intake:poll` (alle 2 Minuten, macht hängende `creating` zu `failed`),
  `intake:prune` (täglich), `intake:orphans`,
  `intake:setup-categories {upn?}`.
- `app/Policies/`: Items (member eigene, admin alle, `process`, `forward`, `createTask`,
  `retryUpload`), Regeln und Benutzer (nur admin).
- `app/Filament/Resources/`: «Eingang» (Triage-Seite `TriageIntakeItem`), «Regeln», «Benutzer».
  `app/Filament/Pages/ClickUpSettings.php`: Admin-Seite «ClickUp».
- `app/Http/Controllers/MailContentController.php`: Original-HTML, Inline-Bilder (signiert),
  Downloads. Mail-HTML nur dort, nie im Panel. `ClickUpConnectionController`: OAuth je User.
- `app/Users/UserAdministration.php`: Rolle, Deaktivieren, Aktivieren.
- `app/ClickUp/`: `ClickUpClient` (einziger ClickUp-Zugang, an ein User-Token gebunden,
  Rate-Limit je Token im Cache), `UserClickUp` (Token je User an genau einer Stelle, Verbinden,
  Trennen, 401, Cache für Listen und Mitglieder), `ClickUpApp` (OAuth-App und Workspace aus
  `settings`). Admin-Seite «ClickUp» unter `/clickup`, OAuth-Routen `/clickup/*`.
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
- Validierungsmeldungen deutsch in `lang/de/validation.php` (nur die genutzten Regeln).
- Gearbeitet wird auf `develop`. `main` bleibt bis zum ersten Deploy unberührt.
- Keine KI-Komponente in V1. Keine Kopplung zum Ticketsystem guycolle.help.

## Betrieb

vps06, `intake.guycolle.services`, Caddy, Queue-Worker als systemd-Unit, Scheduler per Cron.
Queue `retry_after` 360 s, muss über dem längsten Job-Timeout liegen (`PollMailbox`: 300 s).
Einrichtung, Deploy (`deploy/deploy.sh`), Backup und Offboarding: `docs/02-setup.md`.
Anleitung fürs Team: `docs/03-usage.md`.

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
