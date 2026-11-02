# STATUS

Stand 2026-11-02, Branch `develop`.

| Schritt | Inhalt | Stand |
|---|---|---|
| 0 | Konzept-Check, `docs/01-decisions.md` | erledigt, freigegeben |
| 1 | Scaffold, Theme, Microsoft-Login, CI | erledigt |
| 1b | Setup-Assistent «Mit Microsoft 365 verbinden» (`intake:install`) | erledigt, im Tenant geprüft |
| 2 | Datenmodell Intake | umgesetzt, Review-Befunde behoben |
| 3 | Intake über Graph | offen |
| 4 | Triage in Filament | offen |
| 5 | ClickUp-Client und OAuth je User | offen |
| 6 | Tests | laufend je Schritt |
| 7 | Doku und Abschluss | offen |

## Schritt 1

- Laravel 13.39, Filament 5.11, Livewire 4, Pest 5.
- Panel unter `/`, Login nur über Microsoft (`/login`, `/auth/microsoft`, Callback).
  Zugangsdaten der Entra-App nur aus `settings` (verschlüsselt).
- Delegierte Scopes beim Login angefordert, Refresh-Token verschlüsselt am User.
- Theme: Primary `#00AEEF`, Dark `#231F20`, Source Sans 3 lokal, Radius 0, keine Schatten.
- `users` nach E1 bis E3 erweitert, `settings`-Tabelle. Passwort-Spalten entfernt.
- `intake:make-admin`, CI mit Pint, Pest, `composer audit`.

## Reviews nach Schritt 1

Code, Security und Architektur: keine kritischen oder hohen Befunde. Behoben:

- `trustProxies` für Caddy (sonst `http://`-Redirect-URI und ein gemeinsames Login-Throttle).
- Kein «Angemeldet bleiben» mehr (400-Tage-Cookie hätte Entra-Sperren umgangen), Sitzung 8 h.
- `intake:make-admin`: Abbruch bei Mehrdeutigkeit, `--oid`, reaktiviert.
- Tenant-Vergleich ohne Gross-/Kleinschreibung, Tenant muss GUID sein.
- Paralleler Erst-Login ohne 500.
- `.env`-Fallback für Microsoft-Zugangsdaten entfernt (E1, R11c). Nicht entschlüsselbare Settings
  gelten als fehlend.
- `#[SensitiveParameter]` für Tokens, Tests für Fehlerwege im Callback.
- `users`: `ms_access_token`, `ms_token_expires_at` für die zentrale Token-Erneuerung.
- CI: Actions auf SHA gepinnt, Dependabot, `npm audit`. PHP `^8.4`, Seeder leer.
- Entscheide: E2 präzisiert (ein ClickUp-Workspace für die App), E6 neu (`/me` und Dedupe über
  `user_id`). Regeln R4, R7, R11, R11c nachgeführt.

## Vorgaben für 1b aus den Reviews

- Graph-Client in `app/Microsoft/` schon in 1b anlegen, Setup nutzt ihn mit dem Admin-Token.
- Erster Admin über die `oid` aus dem Admin-Token, nie über die E-Mail.
- Handweg `intake:install --manual` schreibt ebenfalls in `settings`.

## Schritt 1b

- `app/Microsoft/GraphClient.php`: einziger Graph-Zugang (R4), an ein Token gebunden, Retry mit
  `Retry-After` bei 429 immer, bei 503 und 504 nicht für POST. `GraphException` ohne Antworttext.
- `app/Microsoft/Setup/`: Gerätecode-Anmeldung (öffentlicher Client «Microsoft Graph Command
  Line Tools», Token nicht gespeichert), `AppRegistrar` (App-Registration, Enterprise-App mit
  Zuweisung erforderlich, Admin-Zustimmung `AllPrincipals`, Secret 24 Monate, idempotent,
  Wiederholung bei Replikationsverzögerung).
- `php artisan intake:install [--renew-secret|--manual|--force]`. Erster Admin über die `oid`.
- Rücksprung-Adresse immer aus `APP_URL` (nicht aus dem Host-Header).
- Reviews 1b (Code, Security): behoben
  - keine Übernahme einer App über den Namen, nur über die gespeicherte Objekt-ID (hoch)
  - Secret-Erneuerung: erst speichern, dann alte entfernen, verwaiste Secrets werden entfernt (hoch)
  - Redirect-URIs ersetzen statt ergänzen, `web` vollständig, eine Registration je Umgebung
  - POST bei 503/504 nicht wiederholen, Replikations-Retry nur für passende Fehler
  - Tenant- und Admin-Bestätigung, kein Reaktivieren deaktivierter User
  - `--manual` prüft Datum und überschreibt nur mit `--force`
  - Gerätecode-Polling übersteht vorübergehende Fehler
  - Tests für Graph-Client und Gerätecode-Ablauf
- 63 Tests grün.
- Lauf gegen den Tenant am 2026-11-02 (lokal, `http://localhost:8000`): App-Registration,
  Enterprise-App, Zustimmung und Secret angelegt, erster Admin gesetzt. Die Registration heisst
  noch «GUYCOLLE ClickUp-Intake» ohne Suffix (vor der Namensregel angelegt), sie wird beim nächsten
  `--force`-Lauf zu «(local)».
- Zweiter Lauf mit `--force` für Port 8001: eigene App über die Objekt-ID gefunden, Rücksprung-
  Adresse ersetzt, umbenannt zu «(local)», altes Secret entfernt.
- Login im Browser mit Gruppenzuweisung erfolgreich (nach Fix: bestehender User scheiterte am
  fehlenden Passwort-Attribut, Test ergänzt).
- Delegierter Token geprüft: Refresh liefert `Mail.ReadWrite Mail.Send MailboxSettings.ReadWrite`,
  `/me/mailFolders/inbox` und `/me/outlook/masterCategories` lesbar.

## Schritt 2

- Tabellen `intake_items`, `intake_attachments`, `intake_rules`, Enums `IntakeStatus`,
  `AttachmentType` (file, item, reference), `RuleMatchType`, Models, Factories.
- Dedupe über eindeutigen Index `(user_id, internet_message_id)` (E6). `mailbox_upn` nur Anzeige.
- `outlook_category_synced_at` am Item: Retention löscht nur, wenn gesetzt (Runde 1, Punkt 10).
- `app/Intake/AttachmentStore.php`: Zeile, dann Datei (in Blöcken aus dem Stream), dann
  `storage_path` (R5, R8). Limit `intake.attachment_max_mb` auch beim Lesen geprüft. Fehler:
  Zeile mit `error`, keine Teildatei. Model-Event löscht `intake/{item_id}`.
- `intake:orphans` meldet Verzeichnisse ohne Item, Dateien ohne Zeile und Zeilen ohne Datei.
- `intake:prune` (täglich 03:30, `withoutOverlapping`) löscht `created`, `forwarded`,
  `discarded` nach `intake.retention_days`, einzeln über Eloquent, danach `intake:orphans`.
- Policies: `member` nur eigene Items, `admin` alle, Regeln nur `admin`, inaktive User nichts.
  Items anlegen und von Hand löschen darf niemand.

## Reviews nach Schritt 2

Code, Security und Architektur: nichts kritisch, ein Befund hoch. Behoben, Entscheide in E7:

- `imported_at` am Item, Triage zeigt nur vollständig importierte Items (hoch).
- Anhänge: eindeutig je `(intake_item_id, graph_attachment_id)`, `selected_for_upload` und
  `upload_error` getrennt von `error`. `users.last_clickup_list_label`.
- `AttachmentStore` schliesst den Quell-Stream immer und hinterlässt auch bei Datenbankfehler
  keine Zeile ohne Pfad und ohne Fehler. `intake:orphans` meldet unvollständige Zeilen und
  Teildateien, wenn sie älter als 15 Minuten sind.
- Verzeichnis des Items wird erst nach dem Commit gelöscht.
- Kategorie-Vertrag: jeder Statuswechsel setzt `outlook_category_synced_at` auf null. Hängende
  Items meldet `intake:prune` mit Fehler, nach 60 Tagen werden sie trotzdem gelöscht
  (bestätigt).
- `strictAuthorization()` im Panel, alle Policy-Methoden explizit, `IntakeAttachmentPolicy`,
  `IntakeItemPolicy::process` (nur `pending` und `failed`).
- Disk `local` ohne `serve`, keine signierte Route `/storage`.
- `IntakeRule::ordered()` (Priorität, dann ID).
- 146 Tests grün.

## Offen und bekannt

- Warnung vor Ablauf des Secrets und Admin-Seite «Microsoft 365» folgen mit Schritt 4 bzw. 7.
- `.env.production.example` bzw. Deploy-Checkliste folgt in Schritt 7 (`docs/02-setup.md`).
- Anhänge liegen unter `storage/app/private/intake` (Wurzel der Disk `local`). E4 nennt für das
  Backup `storage/app/intake`, in Schritt 7 angleichen.
- Statusübergänge an einer Stelle folgen mit Schritt 3 bis 5. Diese Stelle leert
  `outlook_category_synced_at` bei jedem Statuswechsel und setzt es nach dem Kategorie-Update.
