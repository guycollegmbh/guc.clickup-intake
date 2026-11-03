# STATUS

Stand 2026-11-03, Branch `develop`.

| Schritt | Inhalt | Stand |
|---|---|---|
| 0 | Konzept-Check, `docs/01-decisions.md` | erledigt, freigegeben |
| 1 | Scaffold, Theme, Microsoft-Login, CI | erledigt |
| 1b | Setup-Assistent «Mit Microsoft 365 verbinden» (`intake:install`) | erledigt, im Tenant geprüft |
| 2 | Datenmodell Intake | umgesetzt, Review-Befunde behoben |
| 3 | Intake über Graph | umgesetzt, Reviews offen |
| 4 | Triage in Filament (ohne ClickUp-Teile) | umgesetzt, Review-Befunde behoben |
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

## Schritt 4

Ohne ClickUp-Teile (List, Assignees, Due Date, Priorität, Kommentar-Option, «Task erstellen»,
Resource «Regeln»), die folgen in Schritt 5 auf derselben Triage-Seite.

- `app/Intake/TaskName.php`: Betreff ohne führende `Re:`, `AW:`, `WG:`, `Fwd:`, `FW:` (mehrfach,
  gemischt, ohne Gross-/Kleinschreibung), leer wird «(ohne Betreff)».
- `app/Intake/RuleMatcher.php`: erste aktive Rule nach `IntakeRule::ordered()`. `from_domain`
  exakt, ohne Subdomains, führendes `@` wird ignoriert. `from_email` exakt, `subject_contains`
  Teilstring, alles ohne Gross-/Kleinschreibung. Leerer Wert passt nie. Als `scoped` gebunden,
  Rules einmal je Request.
- `app/Intake/MailMarkdown.php` und `SafeLinkConverter.php`: HTML zu Markdown mit
  `league/html-to-markdown`. Entfernt head, script, style, Bilder, eingebettete Inhalte, übrige
  Tags ohne Markdown-Form werden entfernt (Text bleibt). Links nur http, https, mailto, sonst nur
  der Text. Jedes `<`, das ein Tag beginnen könnte, wird `&lt;`. Zellen von Layout-Tabellen
  getrennt. Signaturen und Zitate bleiben.
- `ItemTransitions`: `discard`, `markForwarded`, `markFailed` (bedingt, aus `pending` und
  `failed`, `imported_at` bleibt).
- `app/Intake/TriageActions.php`: Verwerfen und Weiterleiten, prüft selbst die Policy `process`.
  Weiterleiten mit dem Token des Postfach-Inhabers, Kommentar nennt den Handelnden, Empfänger nur
  `INTAKE_SUPPORT_ADDRESS` (ohne gültige Adresse keine Aktion). Erst Graph, dann Status.
  Fehler: `failed` mit Text. Kategorie-Fehler: Status bleibt, Sync später durch den Poll.
- Resource «Eingang» (`/eingang`): Tabelle ohne Bodies, Anhänge ohne inline und Links,
  vorbelegte List (Rule, sonst `last_clickup_list_label` des Inhabers), Filter Status (Vorgabe
  Offen und Fehlgeschlagen), Admins mit Spalte und Filter «Benutzer». Bulk «Verwerfen» je Item mit `process`.
- Triage-Seite (`/eingang/{id}`, View-Seite): Kopf, Taskname, Beschreibung (Markdown-Editor ohne
  Upload), Anhänge als Checkbox-Liste (Vorgabe `selected_for_upload`, Links und fehlerhafte nicht
  wählbar). Werte nur in `$data`, gespeichert wird erst mit «Task erstellen» (Schritt 5). Aktionen
  «An Support weiterleiten», «Verwerfen», «In Outlook öffnen» (nur https, neues Fenster,
  `rel="noopener noreferrer"`).
- Routen ausserhalb des Panels (`MailContentController`): `/mail/{item}/original` (auth, Policy,
  CSP ohne Script, `sandbox`), `/mail/{item}/inline/{attachment}` (nur signiert, 10 Minuten,
  nur Bilder nach finfo, kein SVG), `/mail/attachments/{id}` (auth, Policy, immer Download,
  `application/octet-stream`, `nosniff`, `CSP: sandbox`, bereinigter Name).
- Runde 1, Punkt 8 im Browser geprüft (E9) (Chrome und WebKit, Testseite mit gleichen Headern):
  Das iframe-Dokument erhält das Session-Cookie, die Bildanfragen aus dem Sandbox-iframe in
  Chrome nicht, darum signierte URLs. WebKit lässt `img-src 'self'` im Sandbox-Dokument nicht
  gelten, darum steht der Origin von `APP_URL` zusätzlich in `img-src`. Externe Bilder blockiert,
  Script nicht ausgeführt.
- Resource «Benutzer» (`/benutzer`, nur Admin, `UserPolicy`): Rolle ändern, Deaktivieren (löscht
  Microsoft- und ClickUp-Tokens, Status getrennt), Aktivieren. Nicht für sich selbst.
  `app/Users/UserAdministration.php` prüft die Policy selbst.
- Banner bei unterbrochener Microsoft-Verbindung (Render-Hook) mit Knopf «Abmelden».
- Filament-Texte auf Deutsch, Overrides in `lang/vendor/` für «Schliessen» (ss statt ß).
- 350 Tests grün.

Detailentscheide:

- Verwerfen und Weiterleiten mit Bestätigungsdialog. Nach Erfolg zurück in den Eingang.
- Der Fehlertext eines `failed`-Items bleibt beim Verwerfen erhalten.
- Bilder werden nicht in die Beschreibung übernommen (inline sind Anhänge, externe wären
  Tracking im Editor und in ClickUp).
- Weiterleiten nur für importierte Items, ein Import-Fehler kann bis zum Neu-Import nur
  verworfen werden (E9).

## Reviews nach Schritt 4

Architektur, Code und Security. Behoben, Entscheide in E9 (Inhaber 2026-11-03):

- Teilerfolg (`failed` mit `clickup_task_id`) ist nicht bearbeitbar: `IntakeItem::isProcessable()`
  und Scope `processable()`, Policy `process` und die Übergänge `discard`, `markForwarded`,
  `markFailed` schliessen ihn aus (hoch). Platz für «Fehlende Anhänge erneut hochladen» in
  Schritt 5 kommentiert.
- Weiterleiten unter Sperre je Item (`intake:forward:{id}`). Nach Verbindungsabbruch, 503 oder
  504 lautet der Text «Unklar, ob die Mail weitergeleitet wurde ...». 401 verwirft den
  Access-Token.
- Weiterleiten nur für importierte Items (Policy `forward`), `markFailed` nur mit `imported_at`.
  So überschreibt der Poll keinen Fehlertext der Weiterleitung, und E7 bleibt gewahrt.
- Deaktivierter Inhaber: Verwerfen meldet, dass Outlook nicht mehr angepasst wird. Retention
  löscht solche Items nach 30 Tagen ohne Kategorie-Sync und zählt sie nicht als hängend.
- Standardfilter «Offen» und «Fehlgeschlagen». `visibleInTriage()` zeigt auch erledigte Items
  mit unvollständigem Import.
- Bulk-Verwerfen: nur Statuswechsel im Request, Kategorie per Job `SyncOutlookCategory`,
  Policy je Item mit `Gate::allows`, übersprungene gezählt, Warnung bei 0.
- Beschreibung höchstens 100 000 Zeichen mit «(gekürzt)», Editor mit `maxLength`.
  Klartext-Fallback läuft escaped über den HTML-Weg (keine Links oder Bilder).
- `IntakeAttachment::isUploadable()` als einzige Regel für wählbare Anhänge.
- Deaktivieren nimmt die Token-Sperre von `UserTokens`, `UserTokens` speichert erneuerte
  Tokens nur, solange der User aktiv und verbunden ist.
- 380 Tests grün.

Offen aus Schritt 4:

- Sichtprüfung im Browser (hell, dunkel, Handybreite) braucht einen Microsoft-Login, mit dem
  Inhaber.
- Firefox für die Inline-Bilder ungeprüft (E9).

## Offen und bekannt

- Warnung vor Ablauf des Secrets und Admin-Seite «Microsoft 365» folgen mit Schritt 7.
- `.env.production.example` bzw. Deploy-Checkliste folgt in Schritt 7 (`docs/02-setup.md`).
- Anhänge liegen unter `storage/app/private/intake` (Wurzel der Disk `local`). E4 nennt für das
  Backup `storage/app/intake`, in Schritt 7 angleichen.
- R4 und R7 in `.claude/rules/intake.md` sind noch nicht um E8 ergänzt (Regeldatei, Änderung
  durch den Inhaber).
