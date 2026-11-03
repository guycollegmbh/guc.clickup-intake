# AGENT_PROMPT: guc.clickup-intake

## Kontext

Neues internes Tool der GUYCOLLE GmbH, eigenständiges Repo, keine Abhängigkeit zum Ticketsystem guycolle.help.

**Problem:** Das Team erhält täglich Mails, aus denen ClickUp-Tasks werden sollen. Das offizielle ClickUp-Add-in für Outlook ist im Alltag nicht brauchbar.

**Lösung:** Ein Teammitglied markiert in Outlook (Mac, Web, iPhone) eine Mail in seiner eigenen Mailbox mit der Kategorie **«ClickUp»**. Die App holt die Mail per Microsoft Graph, legt sie in eine Triage-Queue. Im Triage-Formular entsteht daraus ein ClickUp-Task: Betreff als Vorschlag für den Tasknamen, Body als Beschreibung, Anhänge als Task-Attachments, Ziel-List, Assignee(s), Due Date. Danach wird die Kategorie in Outlook auf **«ClickUp ✓»** gesetzt.

**Abgrenzung:** Die App erstellt nur ClickUp-Tasks, nie Tickets. Kundenanliegen gehören ins Ticketsystem guycolle.help. Für versehentlich markierte Kundenmails gibt es den Button «An Support weiterleiten» (Schritt 4), der die Mail an die Support-Mailbox weiterleitet. Das Ticketsystem nimmt sie über seinen normalen Intake entgegen. Es gibt keine Code-Kopplung zwischen den Systemen.

## Grundsätze

- Deterministisch vor KI. In V1 keine KI-Komponente. Vorbelegungen ausschliesslich über Regeln.
- Wartbarkeit vor Eleganz. 5-Personen-Firma, das Tool muss jahrelang ohne Pflege laufen.
- Sprache UI: Deutsch (Schweizer Hochdeutsch, ss statt ß, keine Gedankenstriche, keine Semikolons). Code, Kommentare, Commits, Branch-Namen: Englisch. Conventional Commits.
- Repo-Konvention: `guc.*` = GUYCOLLE-intern. GitHub-Organisation `guycollegmbh`.

## Stack (Vorgabe, in Schritt 0 bestätigen)

- Laravel (aktuelle Major-Version, analog `guc.newsletter`: Laravel 13) + Filament (analog: Filament 5)
- SQLite, Redis (Queue, Cache)
- Theme: `guycollegmbh/guc.filament-theme` (Primary `#00AEEF`, Dark `#231F20`, Source Sans 3, 0px Radius, keine Schatten). Prüfe, ob das Theme mit der gewählten Filament-Version kompatibel ist. Falls nicht: Filament-Version des Themes übernehmen oder als offenen Entscheid melden.
- Tests: Pest, GitHub Actions CI
- Hosting: bestehender IONOS-VPS, Caddy mit automatischem TLS. Kein neuer Server.
- Packages: `microsoft/microsoft-graph` oder schlanker eigener HTTP-Client über `Http::` (Entscheid in Schritt 0, Empfehlung: eigener Client, nur 6 Endpunkte), `league/html-to-markdown`, `socialiteproviders/microsoft-azure`

## Schritt 0: Konzept-Check (vor jedem Code)

Lies dieses Dokument vollständig. Schreibe `docs/01-decisions.md` mit den folgenden Entscheiden, je mit Optionen, Trade-offs und Empfehlung. Stoppe danach und warte auf Freigabe.

1. **Graph-Berechtigung**
   - A: Application Permission `Mail.ReadWrite` + Exchange Application Access Policy, eingeschränkt auf eine Mail-aktivierte Sicherheitsgruppe «ClickUp-Intake». Ein Poll-Job für alle Mailboxen.
   - B: Delegated `Mail.ReadWrite` + `offline_access` pro User beim Entra-Login, Refresh-Token verschlüsselt gespeichert, Poll pro User mit dessen Token.
   - Empfehlung A (kein Token-Lifecycle pro User, kein stilles Ausfallen nach Inaktivität). Liefere das PowerShell-Snippet für die Access Policy mit.
2. **ClickUp-Auth**
   - A: Ein Personal API Token eines Service-Users. Ersteller aller Tasks ist dieser User, Assignees werden gesetzt.
   - B: ClickUp OAuth pro User. Ersteller ist der echte User.
   - Empfehlung A für V1. Melde, ob «Erstellt von = Service-User» im Team ein Problem ist.
3. **Login**: Entra ID SSO über Socialite, beschränkt auf den GUYCOLLE-Tenant. Kein lokales Passwort. Rollen `admin` und `member` über eine Entra-Gruppe oder eine Spalte (Empfehlung: Spalte, erster Admin per Artisan-Command).
4. **Betrieb**: Welcher bestehende VPS, welche Subdomain (Vorschlag `intake.guycolle.services`), Supervisor für Queue-Worker, Cron für Scheduler.
5. **Filament-Version und Theme-Kompatibilität** (siehe Stack).

## Schritt 1: Scaffold

- Laravel-Projekt im Repo-Root, Filament-Panel unter `/`, Login nur über Entra.
- `CLAUDE.md` im Repo mit Stack, Konventionen, Betriebshinweisen und Verweis auf dieses Dokument. Schlank halten.
- `.github/workflows/ci.yml`: Pint, Pest.
- `STATUS.md` mit Arbeitsstand.

## Schritt 2: Datenmodell

**`users`**: `name`, `email`, `entra_object_id` (unique), `mailbox_upn`, `role` (`admin`|`member`), `clickup_member_id` (nullable, für Vorbelegung Assignee), `active`.

**`intake_items`**
- `user_id`, `mailbox_upn`
- `graph_message_id` (veränderlich bei Move, nur Referenz), `internet_message_id` (unique pro Mailbox, Dedupe)
- `subject`, `from_name`, `from_email`, `to_json`, `cc_json`, `received_at`, `web_link`
- `body_html` (longtext), `body_text` (longtext)
- `status`: `pending`, `creating`, `created`, `forwarded`, `discarded`, `failed`
- `clickup_task_id`, `clickup_task_url`, `clickup_list_id`
- `error`, `processed_at`, `processed_by`
- timestamps

**`intake_attachments`**: `intake_item_id`, `graph_attachment_id`, `name`, `content_type`, `size`, `content_id`, `is_inline`, `storage_path`, `uploaded_to_clickup`, `error`.

**`intake_rules`**: `priority`, `match_type` (`from_domain`, `from_email`, `subject_contains`), `match_value`, `clickup_list_id`, `clickup_list_label` (denormalisiert), `assignee_ids` (json), `active`.

**Attachment-Ablage:** Disk `local`, Pfad `intake/{item_id}/{attachment_id}`. Datei und Zeile sind eine Einheit:
- Zeile zuerst anlegen (ohne `storage_path`), dann Datei schreiben, dann `storage_path` setzen. Scheitert das Schreiben, Zeile mit `error` markieren. Kein Zustand «Datei ohne Zeile».
- Löschen nur über das Item (Model-Event löscht Verzeichnis `intake/{item_id}`).
- Command `intake:orphans` meldet Verzeichnisse ohne Item und Zeilen ohne Datei. Läuft im Retention-Job mit.

**Retention:** Items mit Status `created`, `forwarded` oder `discarded` nach 30 Tagen löschen, inklusive Dateien. `failed` und `pending` bleiben. Die Mail selbst bleibt in Outlook.

## Schritt 3: Intake (Graph)

- Command `intake:poll`, alle 2 Minuten über den Scheduler, dispatcht pro aktivem User einen Queue-Job.
- Abfrage: `GET /users/{upn}/messages?$filter=categories/any(c:c eq 'ClickUp')&$select=...&$top=25`. Funktioniert der Filter nicht performant, Fallback auf `mailFolders/inbox/messages` mit `receivedDateTime ge` der letzten 7 Tage plus clientseitigem Filter. Dokumentiere, was funktioniert.
- Attachments: Liste mit `$select=id,name,contentType,size,isInline,contentId`, Inhalt über `/attachments/{id}/$value` (Stream, nicht in den Speicher). `itemAttachment` (angehängte Mails) als `.eml` über `/$value` speichern. `referenceAttachment` (OneDrive-Links) nicht laden, nur als Link in der Beschreibung vermerken.
- Dedupe über `(mailbox_upn, internet_message_id)`.
- Fehler: Status `failed` mit Fehlertext, Item bleibt sichtbar. Keine Mail darf stillschweigend verschwinden.
- Retry mit Backoff bei 429 und 503 (`Retry-After` respektieren).
- Command `intake:setup-categories {upn?}` legt «ClickUp», «ClickUp ✓» und «Support ✓» per `outlook/masterCategories` an, falls nicht vorhanden.

## Schritt 4: Triage (Filament)

**Resource «Eingang»**
- Tabelle: Empfangen, Von, Betreff, Anhänge (Anzahl ohne inline), Status, vorbelegte List. Standardfilter `pending`. Member sehen nur eigene Items, Admins alle (Policy).
- Bulk-Aktion «Verwerfen».

**Triage-Seite (View/Edit-Page des Items)**
- Taskname: vorbelegt mit Betreff ohne führende `Re:`, `AW:`, `WG:`, `Fwd:`, `FW:` (auch mehrfach), editierbar.
- Beschreibung: Markdown-Editor, vorbelegt aus `body_html` (konvertiert). Signaturen und Zitate werden nicht automatisch entfernt.
- Originalansicht: HTML in sandboxed iframe (`sandbox=""`, CSP ohne Script), inline-Bilder über eine authentifizierte Route.
- Ziel-List: Select mit Suche über die ClickUp-Hierarchie, Label «Space / Folder / List». Vorbelegt aus der ersten passenden Rule, sonst zuletzt verwendete List des Users (Spalte am User, nicht Browser-Speicher).
- Assignees: Multi-Select aus ClickUp-Members. Vorbelegt aus Rule, sonst der aktuelle User (`clickup_member_id`).
- Due Date: Datepicker, optional. Schnellwahl Heute, Morgen, in 1 Woche.
- Priorität: optional.
- Anhänge: Checkbox-Liste mit Name, Typ, Grösse. Default: alle nicht-inline angewählt.
- Checkbox «Absender und Mail-Link als Kommentar anfügen», Default an.

**Aktionen**
- «Task erstellen»: Task synchron anlegen, Attachments als Queue-Job, Status `creating` bis alle Uploads durch, dann `created` und Kategorie «ClickUp ✓». Task-Link anzeigen.
- «An Support weiterleiten»: `POST /users/{upn}/messages/{id}/forward` an die konfigurierte Support-Adresse (`INTAKE_SUPPORT_ADDRESS`), Kommentar «Über ClickUp-Intake weitergeleitet von {name}». Status `forwarded`, Kategorie «Support ✓». Bestätigungsdialog vor dem Senden.
- «Verwerfen»: Status `discarded`, Kategorie «ClickUp» entfernen.
- «In Outlook öffnen»: `web_link`.

**Resource «Regeln»** (nur Admin): CRUD, Sortierung nach `priority`.

## Schritt 5: ClickUp-Client

Eigener schlanker Service über `Http::`, Token aus `.env`:
- `hierarchy()`: Spaces, Folders, folderless Lists, Lists. Cache 1 h in Redis, Admin-Aktion «Neu laden».
- `members()`: Cache 1 h.
- `createTask(listId, data)`: `name`, `markdown_description`, `assignees`, `due_date` (ms, `due_date_time=false` bei reinem Datum), `priority`.
- `attach(taskId, path, filename)`: multipart, sequenziell, 429 mit Backoff.
- `comment(taskId, text)`.
- ClickUp-Limit: 100 Requests pro Minute pro Token. Rate-Limiter im Client.

## Schritt 6: Tests (Pest)

- Feature: Poll-Job mit Graph-Fixtures (`Http::fake()`), Dedupe, Inline-Erkennung, itemAttachment, Fehlerpfad.
- Feature: Task erstellen inkl. Attachments, Kommentar, Kategorie-Update.
- Feature: Weiterleiten und Verwerfen, jeweils mit Kategorie-Update.
- Feature: Attachment-Ablage in beide Richtungen (Schreibfehler → Zeile mit `error`, keine Datei. Erfolg → Datei und Zeile vorhanden). Retention löscht Item und Verzeichnis. `intake:orphans` meldet 0.
- Unit: Betreff-Normalisierung, Rule-Matching (Reihenfolge, Domain vs E-Mail, inaktive Rules).
- Policy: Member sieht nur eigene Items, Admin alle.

## Schritt 7: Doku und Abschluss

- `docs/02-setup.md`: Entra-App-Registration (Redirect-URI, Graph-Permissions, Admin Consent), Access Policy, ClickUp-Token, `.env`-Variablen, Kategorien-Setup, Caddy, Supervisor, Cron.
- `docs/03-usage.md`: Anleitung fürs Team in 3 Schritten plus Entscheidungsregel: Alles, was eine Antwort an einen Kunden erfordert, gehört ins Ticketsystem (weiterleiten). Interne Arbeiten, Lieferanten, Projektmails ohne Kundenantwort sind Tasks.
- `STATUS.md` nachführen.

## Nicht in V1

- Keine KI-Vorbelegung (List, Assignee, Zusammenfassung). Frühestens nach 4 Wochen Nutzungsdaten.
- Kein «Tasks in Multiple Lists».
- Kein «an bestehenden Task anhängen».
- Keine Shared Mailboxes.
- Kein Outlook-Add-in (siehe Phase C).

---

# Phase C (optional, erst nach V1 im Betrieb): Outlook-Button

Entscheid nach 2 bis 4 Wochen Betrieb. Nur bauen, wenn «Kategorie setzen, dann in die App wechseln» im Alltag wirklich stört.

- Outlook-Add-in mit genau einem Button «An ClickUp». Klick öffnet die Triage-Seite der App für diese Mail in einem Dialog. Keine Businesslogik im Add-in.
- Unified Manifest (JSON), Assets statisch aus der App unter `/outlook-addin/`.
- Flow: `Office.context.mailbox.item.itemId` → `convertToRestId()` → `displayDialogAsync('https://<app>/intake/from-outlook?rest_id=...')`.
- Endpunkt `/intake/from-outlook`: Auth über die bestehende App-Session, holt die Mail ad hoc über denselben Import-Service wie der Poll-Job, Dedupe wie gehabt, Redirect auf die Triage-Seite.
- Kein Office-SSO, kein Token im Add-in.
- Deployment über Integrated Apps im M365 Admin Center, Zuweisung an die Gruppe «ClickUp-Intake».
- Akzeptanz: Klick im Mac-Outlook öffnet innert 3 s die vorbelegte Triage-Seite. Fällt das Add-in aus, funktioniert der Kategorie-Weg unverändert.
