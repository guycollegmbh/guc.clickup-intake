# 01: Entscheide (Schritt 0, Konzept-Check)

Stand 2026-11-02, Runde 2. Status: **E1 bis E5 freigegeben am 2026-11-02, verbindlich** (siehe
`.claude/rules/intake.md`). Tenant-Prüfung: Gerätecode-Ablauf erlaubt, keine bekannte
Richtlinie zur Anmeldehäufigkeit.

Grundlage: `docs/AGENT_PROMPT.md`, die Nachbar-Repos `guc.filament-theme`, `guc.quotebot`,
`guc.cronman`, das Inventar in `guc.ansible` und die Rückmeldung des Inhabers vom 2026-11-02:

- Einrichtung wie bei modernen Apps: Ein M365-Admin legt die App an und erteilt die
  Berechtigungen, ohne Handarbeit im Entra-Portal oder in PowerShell.
- Zugang über eine Gruppe im Tenant, die der App zugewiesen wird.
- Jeder arbeitet mit dem eigenen ClickUp-User.
- Neues, einfaches Theme.

---

## E1: Microsoft 365, Einrichtung und Berechtigung (geändert)

### Modell: delegierte Berechtigungen statt Application Permission

Runde 1 empfahl eine Application Permission mit Exchange Access Policy. Die Access Policy lässt
sich nur per Exchange-PowerShell anlegen, nicht über Graph. Eine App, die sich per Admin-Klick
einrichtet, kann sie also nicht selbst setzen. Ohne sie hätte die App Zugriff auf **alle**
Postfächer im Tenant. Darum jetzt:

| | Delegated (neu) | Application + Access Policy (Runde 1) |
|---|---|---|
| Einrichtung | vollständig im Setup-Assistenten | Setup-Assistent plus PowerShell von Hand |
| Reichweite | nur die Postfächer der angemeldeten, zugewiesenen User, jeder nur das eigene | alle Postfächer, falls die Policy fehlt oder falsch ist |
| Zugang | eine Gruppe, der App zugewiesen, steuert Login **und** Mailzugriff | Gruppe für Login, zweite Gruppe für die Policy |
| Kategorien anlegen | automatisch beim ersten Login im eigenen Postfach | Command für alle Postfächer |
| Schwachpunkt | Refresh-Token je User (siehe unten) | keiner im Betrieb |

Delegierte Scopes, beim Login angefordert, einmal per Admin Consent für alle erteilt (keine
Zustimmungsabfrage für die User):

`openid profile email offline_access User.Read Mail.ReadWrite Mail.Send MailboxSettings.ReadWrite`

### Token-Lifecycle und wie er beherrschbar bleibt

- Der Poll alle 2 Minuten erneuert den Refresh-Token laufend. Die 90 Tage Inaktivität werden nie
  erreicht.
- Ungültig wird er bei Passwort-Reset, Sperrung, Widerruf durch den Admin, Entzug der
  Gruppenzuweisung. Dann: User-Status «Microsoft-Verbindung unterbrochen», sichtbar im
  Admin-Dashboard und als Banner. Der nächste Login repariert es automatisch.
- **Voraussetzung:** Keine Conditional-Access-Richtlinie mit «Anmeldehäufigkeit» auf dieser App,
  sonst bricht der Poll in diesem Takt. Falls es eine solche Richtlinie gibt, die App davon
  ausnehmen.

### Setup-Assistent («Mit Microsoft 365 verbinden»)

Umgesetzt in Schritt 1b als interaktiver Befehl `php artisan intake:install` statt einer Webseite
`/setup` (gleicher Ablauf für den Admin, keine unauthentisierte Einrichtungs-Route). Erster Lauf
gegen den Tenant am 2026-11-02 erfolgreich.

1. Der Admin meldet sich per Gerätecode an (öffentlicher Client «Microsoft Graph Command Line
   Tools»). Angefordert werden nur `Application.ReadWrite.All` und
   `DelegatedPermissionGrant.ReadWrite.All`, ohne `offline_access`. Der Token wird nicht
   gespeichert. «Im Namen der Organisation zustimmen» nicht ankreuzen.
2. Tenant und Konto werden angezeigt und müssen bestätigt werden. Bei bestehender Einrichtung
   muss der Tenant übereinstimmen.
3. Der Befehl legt per Graph an: App-Registration (Single-Tenant, eine je Umgebung, ausserhalb
   von Produktion mit Suffix wie «(local)»), Enterprise-App mit «Zuweisung erforderlich»,
   Admin-Zustimmung `AllPrincipals` für die Scopes oben, Client-Secret (24 Monate).
4. Eine bestehende Registration wird **nur über die selbst gespeicherte Objekt-ID** gefunden,
   nie über den Namen (sonst könnte eine vorab angelegte fremde App die Mail-Rechte erhalten).
   Die IDs werden direkt nach dem Anlegen gespeichert, ein abgebrochener Lauf setzt dort fort.
5. Rücksprung-Adressen werden auf genau die Adresse dieser Installation gesetzt (aus `APP_URL`),
   nicht ergänzt. Nach dem Speichern des neuen Secrets werden alle anderen Secrets entfernt.
6. Zugangsdaten verschlüsselt in `settings`, kein `.env`. Handweg: `intake:install --manual`.
7. Der angemeldete Admin wird nach Rückfrage erster App-Admin (über die `oid`). Ein in der App
   deaktivierter User wird nicht reaktiviert.
8. Danach weist der Admin der Enterprise-App die Gruppe zu, der Befehl zeigt den Link.

Secret erneuern: `php artisan intake:install --renew-secret` (gleicher Gerätecode-Ablauf, neues
Secret zuerst gespeichert, dann die alten entfernt). Warnung vor Ablauf und eine Admin-Seite
«Microsoft 365» mit dem Status folgen später. Kein Dauerrecht der App auf die eigene
Registration.

**Voraussetzung:** Der Gerätecode-Ablauf ist im Tenant nicht per Conditional Access gesperrt.
Falls doch: für die Einrichtung kurz ausnehmen oder Fallback «App-Registration von Hand» nach
`docs/02-setup.md`. Der Assistent übernimmt dann ab Schritt 4.

### Empfehlung

Delegierte Berechtigungen, Einrichtung über den Setup-Assistenten, Zugang über die zugewiesene
Gruppe. Kein PowerShell, keine Access Policy, minimale Reichweite.

---

## E2: ClickUp, jeder mit eigenem User (geändert)

Ja, das geht: ClickUp OAuth.

- Einmalig legt ein ClickUp-Admin unter Settings, Integrations, ClickUp API eine OAuth-App an
  (Name, Redirect-URL `https://intake.guycolle.services/clickup/callback`) und trägt Client-ID
  und Secret auf der Admin-Seite «ClickUp» ein. Dafür gibt es keine API, es sind etwa 2 Minuten.
- Der ClickUp-Workspace ist einer für die ganze App (Setting `clickup.team_id`, gewählt auf der
  Admin-Seite «ClickUp»). Regeln, Hierarchie und Members beziehen sich darauf. Präzisiert am
  2026-11-02 nach Architektur-Review.
- Jeder User klickt einmal «Mit ClickUp verbinden». Die App prüft, dass sein Token Zugriff auf
  den App-Workspace hat. Ohne Verbindung ist
  «Task erstellen» gesperrt mit Hinweis.
- Tasks, Kommentare und Anhänge laufen mit dem Token des Users: «Erstellt von» ist der echte User,
  ClickUp-Rechte (private Spaces) gelten wie gewohnt.
- Laut ClickUp-Doku laufen OAuth-Tokens derzeit nicht ab. In Schritt 5 verifizieren. Bei 401:
  Status «ClickUp-Verbindung unterbrochen», neu verbinden.
- Hierarchie wird je User geladen und gecacht (sieht nur, was er in ClickUp sieht).
  Rate-Limit je Token.
- `clickup_member_id` am User wird bei der Verbindung automatisch gesetzt, kein Pflegefeld mehr.
- Wird ein User deaktiviert, wird sein Token gelöscht.

### Empfehlung

ClickUp OAuth je User. Kein Service-User, kein zusätzlicher Sitz.

---

## E3: Login und Rollen (freigegeben)

- Entra-Login über Socialite mit der App aus E1, Single-Tenant, `tid` und `oid` geprüft.
  Kein lokales Passwort.
- Zugang: Gruppe im Tenant, der Enterprise-App zugewiesen («Zuweisung erforderlich»). Wer nicht
  zugewiesen ist, kommt bei Microsoft nicht durch. User wird beim ersten Login angelegt.
- Rollen als Spalte `role`, nicht mass-assignable. Erster Admin ist, wer den Setup-Assistenten
  abschliesst. Zusätzlich `php artisan intake:make-admin {email}` als Notweg.

---

## E4: Betrieb (freigegeben)

- **vps06** (guc.dns, guc.cronman, Caddy, Redis, PHP 8.4), Subdomain **intake.guycolle.services**.
- Queue-Worker als **systemd-Unit** (`clickup-intake-worker.service`), nicht Supervisor. Begründung:
  auf vps06 laufen die Worker bereits als systemd-Units, ein Host soll nur eine
  Prozessverwaltung haben, und systemd braucht kein zusätzliches Paket.
- Scheduler per Cron, Redis mit eigener DB-Nummer und Prefix.
- Backup: SQLite und `storage/app/intake` in den bestehenden Lauf von guc.backup.
- Eintrag in `guc.ansible` unter `urls`.

---

## E5: Stack und Theme (freigegeben)

- Laravel 13, Filament 5, PHP 8.4, SQLite, Redis, Pest.
- **Neues, einfaches Theme im Repo** (Filament-Theme über Vite): Primary `#00AEEF`, Dark `#231F20`,
  Source Sans 3 (selbst gehostet, kein Google-Fonts-Aufruf), Radius 0, keine Schatten,
  Logo und Favicon. Hell und dunkel. Ziel unter 150 Zeilen CSS, damit Filament-Updates es selten
  brechen. `guc.filament-theme` bleibt unverändert für die Filament-3-Apps.

---

## E6: Postfach-Adressierung und Dedupe (Folge aus E1, 2026-11-02)

Mit delegierten Tokens ist das Postfach immer das des Token-Inhabers. Darum:

- Graph-Aufrufe über `/me/...`, nicht `/users/{upn}/...`.
- Dedupe über `(user_id, internet_message_id)` statt `(mailbox_upn, internet_message_id)`.
  Eine UPN-Umbenennung führt sonst zu Doppelimporten.
- `mailbox_upn` am Item nur zur Anzeige.

Ersetzt die entsprechenden Stellen in Schritt 2 und 3 der Spezifikation.

---

## E7: Datenmodell-Details nach Review Schritt 2 (2026-11-02)

Aus den Reviews zu Schritt 2. Ergänzt Schritt 2 und 3 der Spezifikation.

- **`imported_at`** am Item: gesetzt erst, wenn Item und alle Anhänge vollständig importiert sind.
  Die Triage zeigt nur Items mit `imported_at` (Scope `imported()`). Halbe Importe bleiben
  unsichtbar und werden in Schritt 3 neu importiert.
- **Anhänge:** `error` nur für Fehler bei der Ablage. Für ClickUp `selected_for_upload` (Auswahl in
  der Triage) und `upload_error`. Eindeutiger Index `(intake_item_id, graph_attachment_id)`.
- **Kategorie-Vertrag:** Jeder Statuswechsel setzt `outlook_category_synced_at` auf null. Gesetzt
  wird es erst nach erfolgreichem Kategorie-Update. Die Stelle dafür entsteht in Schritt 3.
- **Retention-Obergrenze:** Erledigte Items ohne synchronisierte Kategorie gelten nach 30 Tagen als
  hängend (`intake:prune` meldet sie und endet mit Fehler). Nach 60 Tagen
  (`intake.retention_hard_limit_days`) werden sie trotzdem gelöscht, mit Log-Eintrag nur mit
  Item- und User-ID. **Zur Bestätigung durch den Inhaber.**
- **referenceAttachment** (Variante a): nur Name als Zeile ohne Datei, in der Triage mit Hinweis
  «Link in der Original-Mail». Keine Spalte für die URL, kein Beta-Endpunkt.
- **Fehlende `internetMessageId`:** Ersatzschlüssel wird in Schritt 3 festgelegt. Vorschlag
  `graph:{immutableId}` (Graph mit `Prefer: IdType="ImmutableId"`).
- **Verhalten des Polls bei Dedupe-Treffer** (umgesetzt in Schritt 3):

| Bestehendes Item | Verhalten |
|---|---|
| `discarded`, Kategorie synchronisiert (erneut markiert) | zurück auf `pending` (Runde 1, Punkt 6) |
| `discarded` oder `created` ohne synchronisierte Kategorie | Kategorie erneut setzen |
| `failed` ohne `clickup_task_id` | Import wiederholen |
| alle anderen | nichts |

- **Security-Hinweis für Schritt 3:** Eine gleiche Message-ID mit abweichender Mail (z. B. anderer
  Absender oder Betreff) wird nicht still übersprungen, sondern sichtbar gemacht.

---

## Weitere Punkte aus Runde 1

| # | Punkt | Stand |
|---|---|---|
| 1 | Fehlende Graph-Permissions | gelöst durch E1 (delegiert, inkl. `Mail.Send`, `MailboxSettings.ReadWrite`) |
| 2 | Theme inkompatibel | gelöst durch E5 |
| 3 | Supervisor oder systemd | entschieden: systemd (E4) |
| 4 | Wer darf sich anmelden | gelöst durch Gruppenzuweisung (E3) |
| 5 | Teilerfolg bei «Task erstellen» | angenommen: `failed` mit `clickup_task_id`, Aktion «Fehlende Anhänge erneut hochladen» |
| 6 | Erneut markieren nach «Verwerfen» | angenommen: Item zurück auf `pending` |
| 7 | Kategorien | angenommen: «ClickUp» wird durch «ClickUp ✓» bzw. «Support ✓» ersetzt, andere bleiben |
| 8 | Inline-Bilder im sandboxed iframe | kurzlebige signierte URLs, in Schritt 4 verifizieren |
| 9 | ClickUp-Feldname Beschreibung | in Schritt 5 prüfen |
| 10 | Retention vor Kategorie-Update | Item erst löschen, wenn die Kategorie gesetzt ist |
| 11 | `guc.newsletter` nicht lokal | Versionen gegen `guc.bexio-abgleich` geprüft |
| 12 | Grösse von Anhängen | Limit 50 MB je Anhang, konfigurierbar |

## Auswirkungen auf die Spezifikation

- Datenmodell `users`: zusätzlich `ms_refresh_token` (verschlüsselt), `ms_connection_status`,
  `clickup_token` (verschlüsselt), `clickup_connection_status`, `last_clickup_list_id`.
  `mailbox_upn` kommt aus dem Login.
- Neue Tabelle `settings` (verschlüsselte Werte) für App-Registration und ClickUp-OAuth-App.
- `intake:poll` dispatcht nur für User mit gültiger Microsoft-Verbindung.
- `intake:setup-categories` entfällt als Pflicht-Schritt, läuft automatisch beim Login. Der
  Command bleibt für Reparaturen.
- ClickUp-Client arbeitet je User-Token, Cache und Rate-Limit je User.
- `docs/02-setup.md` beschreibt den Assistenten statt Portal-Klicks und PowerShell.
