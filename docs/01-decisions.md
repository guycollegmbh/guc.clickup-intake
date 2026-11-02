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

1. `php artisan intake:install` auf dem Server gibt einen einmaligen Setup-Link aus. `/setup` ist
   nur erreichbar, solange die App nicht eingerichtet ist, und nur mit diesem Link.
2. Der Admin meldet sich im Assistenten per Gerätecode an (microsoft.com/devicelogin) über den
   öffentlichen Client «Microsoft Graph Command Line Tools», denselben, den `Connect-MgGraph`
   verwendet. Der Assistent fordert dafür nur befristet `Application.ReadWrite.All` und
   `DelegatedPermissionGrant.ReadWrite.All` an. Dieser Token wird nicht gespeichert.
3. Der Assistent legt per Graph an:
   - App-Registration «GUYCOLLE ClickUp-Intake», Single-Tenant, Redirect-URI
     `https://intake.guycolle.services/auth/microsoft/callback`, Logo, die Scopes oben
   - Client-Secret (24 Monate)
   - Service Principal (Enterprise-App) mit «Zuweisung erforderlich» = ja
   - Admin Consent für die Scopes (`oauth2PermissionGrant`, `AllPrincipals`)
4. Client-ID, Tenant-ID und Secret landen verschlüsselt in der Datenbank (`settings`, verschlüsselt
   mit `APP_KEY`), nicht in `.env`.
5. Der Admin wird erster App-Admin. Danach weist er der Enterprise-App die Gruppe zu (im
   Entra-Portal, wie geplant). Der Assistent zeigt den direkten Link dorthin.

Admin-Seite «Microsoft 365» danach: Status der App, gewährte Scopes, Ablauf des Secrets,
verbundene User, Knopf «Secret erneuern» (gleicher Gerätecode-Ablauf, Warnung ab 30 Tage vor
Ablauf, Mail an alle Admins). Kein Dauerrecht der App auf die eigene Registration.

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
- Jeder User klickt einmal «Mit ClickUp verbinden» und wählt den Workspace. Ohne Verbindung ist
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
