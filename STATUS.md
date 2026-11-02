# STATUS

Stand 2026-11-02, Branch `develop`.

| Schritt | Inhalt | Stand |
|---|---|---|
| 0 | Konzept-Check, `docs/01-decisions.md` | erledigt, freigegeben |
| 1 | Scaffold, Theme, Microsoft-Login, CI | erledigt |
| 1b | Setup-Assistent «Mit Microsoft 365 verbinden» (`intake:install`) | erledigt, im Tenant geprüft |
| 2 | Datenmodell Intake | umgesetzt, Review-Befunde behoben |
| 3 | Intake über Graph | umgesetzt, Reviews offen |
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

## Schritt 3

- `app/Microsoft/UserTokens.php`: einzige Stelle für die Token-Erneuerung je User. Access-Token
  verschlüsselt am User, Erneuerung 10 Minuten vor Ablauf unter `Cache::lock` je User, neuer
  Refresh-Token sofort gespeichert. `invalid_grant`, `interaction_required`, `consent_required`
  setzen `broken` und löschen die Tokens (`MicrosoftConnectionBroken`). 5xx, Netzwerk,
  `invalid_client` und Sperr-Timeout ändern den Status nicht (`MicrosoftTokenUnavailable`).
  Inaktive User erhalten keinen Token. `graphFor()` liefert einen GraphClient mit
  `Prefer: IdType="ImmutableId"`, `forget()` verwirft den Access-Token nach einer 401.
- `GraphClient`: Prefer-Werte je Client und je Aufruf (ein Header), `stream()` für
  `$value` (Ressource, gleiche Retry-Regeln, R5), absolute URLs nur unter `BASE_URL` (nextLink).
  Behoben: eine leere `query`-Option hätte den Query-String des nextLink entfernt.
- `app/Microsoft/OutlookCategories.php`: `ensureMasterCategories()` legt nur fehlende an,
  `replace(user, graphId, from, to)` ersetzt eine Kategorie, behält alle anderen, vergleicht
  ohne Gross-/Kleinschreibung und prüft die Antwort von Graph. 404 gilt als erledigt.
  Job `SetupOutlookCategories` nach jedem Login, `intake:setup-categories {upn?}` für Reparaturen.
- `app/Intake/ItemTransitions.php`: einzige Stelle für Statuswechsel, jeder als bedingtes
  Update mit erlaubten Ausgangszuständen (gibt `bool` zurück): `claimForImport`,
  `markImportFailed`, `backToPending`, `syncCategory`, `alignCopy`. `IntakeStatus::isFinal()`
  und `isProcessable()` ersetzen die Listen in Retention und Policy.
- `app/Intake/MessageImporter.php`: Import nach E7 und E8, gibt das Item zurück (Phase C).
  Item entsteht zuerst aus der Übersicht, damit auch ein Fehler beim Laden der Mail sichtbar
  wird. Import-Fehler: `failed`, `imported_at` leer, Neu-Import mit Wartezeit.
- `app/Intake/MailboxPoller.php`, Job `PollMailbox` (eindeutig je User, Timeout 300 s,
  `retry_after` der Queue 360 s), `intake:poll` alle 2 Minuten (`withoutOverlapping`). Prüft
  zuerst den freien Platz, macht abgebrochene Importe zu Import-Fehlern, beginnt nach 240 s
  keine weitere Mail, setzt danach `users.ms_last_polled_at`. Graph-Fehler beim Lesen der Liste
  beenden den Job ohne Eintrag in `failed_jobs`.
- Migrationen: `intake_items.import_attempts`, `next_import_at`, `users.ms_last_polled_at`.

## Reviews nach Schritt 3

Code, Security und Architektur: drei Befunde hoch, alle behoben, Entscheide in E8:

- `$select` der Anhangsliste in der im Tenant geprüften Form (hoch). FakeGraph beantwortet
  nacktes `contentId` wie der Tenant mit 400.
- Neu-Import nur bei Import-Fehlern, mit Wartezeit 2 bis 60 Minuten (hoch). Ein `failed` aus
  späteren Schritten wird vom Poll nicht mehr angefasst (vorher Endlosschleife alle 2 Minuten).
- Bedingte Statusübergänge, ein paralleles «Verwerfen» wird nicht überschrieben (hoch).
- Abgebrochene Importe, Frist im Poll, Kategorien ohne Gross-/Kleinschreibung, Kopien einer Mail,
  401 beim Lesen der Liste, inaktive User ohne Token.
- Grenzen `item_max_mb` und `min_free_disk_mb`, Anhangnamen bereinigt, Inhaltstyp aus den Bytes,
  gespeicherte Grösse aus den geschriebenen Bytes.
- `ImportOutcome` entfernt (R17), der Importer gibt das Item zurück.
- 254 Tests grün.

Detailentscheide:

- Anhänge: Inline gilt auch, wenn der Body `cid:{contentId}` referenziert (Graph meldet
  `isInline` nicht immer). Zu grosse Anhänge werden nicht geladen, die Zeile erhält den Fehler,
  das Item wird trotzdem importiert. Ein fehlgeschlagener Download ist ein Import-Fehler.
  `selected_for_upload` nur für nicht inline, nicht reference und ohne Fehler.
- `graph_message_id` folgt Kopien nicht. Ist die erste Mail gelöscht, scheitert ein Neu-Import
  mit 404 (sichtbar), das Item wird dann verworfen.
- Body als HTML angefordert, `body_text` ohne neues Paket aus dem HTML. Reiner Text wird
  escaped als `body_html` abgelegt.

Gegen den Tenant noch zu prüfen:

- Filter `categories/any(...)` auf `/me/messages`: Antwortzeit und ob Graph ihn ablehnt
  (welcher Fehlercode, passt die Liste für den Fallback?).
- Felder eines referenceAttachment in v1.0.
- `Prefer: IdType="ImmutableId", outlook.body-content-type="html"` in einem Header, ID bleibt
  nach Verschieben gleich, `/$value` für itemAttachment liefert MIME, PATCH auf die Mail
  liefert `categories` zurück.
- Streaming über Guzzle nutzt bei `allow_url_fopen=On` den Stream-Handler, sonst schreibt cURL
  in einen temporären Stream. Auf vps06 prüfen.

## Offen und bekannt

- Warnung vor Ablauf des Secrets und Admin-Seite «Microsoft 365» folgen mit Schritt 4 bzw. 7.
- `.env.production.example` bzw. Deploy-Checkliste folgt in Schritt 7 (`docs/02-setup.md`).
- Anhänge liegen unter `storage/app/private/intake` (Wurzel der Disk `local`). E4 nennt für das
  Backup `storage/app/intake`, in Schritt 7 angleichen.
- Beim Deaktivieren eines Users müssen seine Microsoft- und ClickUp-Tokens gelöscht werden.
  Kommt mit der Admin-Aktion in Schritt 4. Bis dahin verweigert `UserTokens` inaktiven Usern
  jeden Token.
- R4 und R7 in `.claude/rules/intake.md` sind noch nicht um E8 ergänzt (Regeldatei, Änderung
  durch den Inhaber).
