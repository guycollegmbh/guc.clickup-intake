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
  Item- und User-ID. Bestätigt durch den Inhaber am 2026-11-02.
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
- **referenceAttachment Variante a** bestätigt durch den Inhaber am 2026-11-02.
- **Kein manuelles Löschen von Items**, auch nicht für Admins und nicht für `failed` (Inhaber,
  2026-11-02). Items verschwinden nur über die Retention, `failed` wird über «Verwerfen» erledigt.

---

## E8: Import-Details nach Review Schritt 3 (2026-11-02)

Aus den Reviews zu Schritt 3. Ergänzt E6 und E7.

- **Ersatzschlüssel** in `internet_message_id`: ohne Message-ID `graph:{immutableId}`. Gleiche
  Message-ID, aber andere Mail (andere Graph-ID und abweichende Empfangszeit oder Absender):
  `{Message-ID}#{immutableId}`, eigenes Item, nicht still übersprungen. Graph-IDs immer mit
  `Prefer: IdType="ImmutableId"`.
- **Neu-Import nur bei Import-Fehlern.** Ein Import-Fehler lässt `imported_at` leer, setzt
  `failed` mit Fehlertext, `import_attempts` und `next_import_at`. Wartezeit 2, 4, 8 ... Minuten,
  höchstens 60. Der Poll importiert neu, wenn `imported_at` leer, der Status `pending` oder
  `failed` und die Wartezeit vorbei ist. Ein `failed` aus späteren Schritten (Weiterleiten,
  ClickUp) hat `imported_at` gesetzt und wird vom Poll nie angefasst. Die Triage zeigt Items
  mit `imported_at` oder Status `failed` (Scope `visibleInTriage()`).
- **Abgebrochene Importe** (`pending` ohne `imported_at`, älter als 15 Minuten) werden zu Beginn
  jedes Polls zu Import-Fehlern «Der Import wurde abgebrochen.», ihre Anhänge werden entfernt.
  Ein Poll beginnt nach 240 Sekunden keine weitere Mail.
- **Dedupe-Tabelle (E7) ergänzt:** auch `forwarded` ohne synchronisierte Kategorie bekommt die
  Kategorie erneut. `discarded` wird nur wieder geöffnet, wenn die Kategorie vor dem Lesen der
  Mail-Liste synchronisiert war.
- **Kopien** derselben Mail (gleiche Message-ID, Empfangszeit und Absender, andere Graph-ID):
  Ist das Item erledigt, bekommt die Kopie die Kategorie des Ergebnisses. Das Item wird nicht
  geöffnet, `graph_message_id` bleibt die der ersten Mail.
- **Kategorien** werden ohne Gross-/Kleinschreibung verglichen (wie Outlook). Synchronisiert
  gilt erst, wenn Graph nach dem Update keine Variante von «ClickUp» mehr meldet und die
  Ziel-Kategorie gesetzt ist.
- **Grenzen:** `intake.attachment_max_mb` (50) je Anhang, `intake.item_max_mb` (100) für alle
  Anhänge einer Mail. Darüber wird nicht geladen, die Zeile erhält einen Fehlertext.
  Unter `intake.min_free_disk_mb` (1024) freiem Platz importiert der Poll nichts.
- **Anhangsliste:** `$select=id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId`.
  Im Tenant am 2026-11-02 geprüft: nacktes `contentId` ergibt 400, die qualifizierte Form 200
  mit `@odata.type` und `contentId`, ohne `contentBytes`.
- **Anhangnamen** werden beim Import bereinigt (Steuer- und Bidi-Zeichen, Pfadtrenner), der
  Inhaltstyp wird aus den gespeicherten Bytes bestimmt, nicht vom Absender übernommen.
- **Filter-Fallback:** Lehnt Graph `categories/any(...)` mit 400 und `InefficientFilter`,
  `ErrorInvalidRestriction` oder `ErrorInvalidUrlQueryFilter` ab, liest der Poll den Posteingang
  der letzten 7 Tage und filtert in der App. Ob der Filter im Tenant performant ist, ist noch zu
  prüfen.

---

## E9: Triage-Details Schritt 4 (2026-11-03)

Aus der Umsetzung und den Reviews zu Schritt 4, mit Entscheiden des Inhabers vom 2026-11-03.
Ergänzt Schritt 4 der Spezifikation und E7, E8.

- **Inline-Bilder** über eine signierte, relative Route (`/mail/{item}/inline/{attachment}`,
  10 Minuten gültig), nicht über die Session. Im Browser geprüft: Chrome schickt aus einem
  iframe mit `sandbox=""` kein Session-Cookie mit den Bildanfragen. Die Route liefert nur Bilder
  des Items nach dem Inhalt der Datei, kein SVG.
- **CSP der Original-Ansicht:** zusätzlich zu `'self'` steht der Origin von `APP_URL` in
  `img-src`. WebKit (Safari) lässt `'self'` im Sandbox-Dokument nicht gelten, ohne den Origin
  fehlen dort die Inline-Bilder. Dazu `base-uri 'none'`, `form-action 'none'` und `sandbox`, damit
  die Route auch direkt geöffnet gesperrt bleibt. Firefox ist ungeprüft.
- **Resource «Benutzer»** (nur Admin), zusätzlich zur Spezifikation: Rolle ändern, Deaktivieren
  (löscht Microsoft- und ClickUp-Tokens, unter derselben Sperre wie die Token-Erneuerung),
  Aktivieren. Nicht für sich selbst.
- **Resource «Regeln»** wird nach Schritt 5 verschoben, sie braucht die List-Auswahl aus ClickUp.
- **Standardfilter** im Eingang: «Offen» und «Fehlgeschlagen» (Inhaber), damit keine
  fehlgeschlagene Mail übersehen wird.
- **Teilerfolg** (`failed` mit `clickup_task_id`) ist nicht bearbeitbar wie ein offenes Item:
  Verwerfen, Weiterleiten und «Task erstellen» schliessen es aus (`IntakeItem::isProcessable()`).
  Schritt 5 behandelt es mit eigener Berechtigung und eigenem Übergang («Fehlende Anhänge erneut
  hochladen», Runde 1, Punkt 5).
- **Weiterleiten** nur für vollständig importierte Items. Ein Import-Fehler wird vom Poll neu
  importiert (E8) und würde einen Fehlertext der Weiterleitung überschreiben. `imported_at` zu
  setzen, obwohl Anhänge fehlen können, widerspräche E7. Bis zum erfolgreichen Neu-Import kann
  eine solche Mail nur verworfen werden.
- **Weiterleiten** läuft unter einer Sperre je Item. Nach Verbindungsabbruch, 503 oder 504 ist
  unklar, ob Graph die Mail gesendet hat: Der Fehlertext verweist auf «Gesendete Elemente» statt
  auf einen neuen Versuch.
- **Bulk-Verwerfen** ändert im Request nur den Status, die Kategorie setzt ein Job je Item.
  Einzel-Verwerfen bleibt synchron.
- **Mails deaktivierter User** (Inhaber): Die Kategorie lässt sich ohne Token nicht mehr ändern.
  Verwerfen meldet das ehrlich, die Retention zählt solche Items nicht als hängend und löscht sie
  nach `retention_days` auch ohne synchronisierte Kategorie (Log nur mit IDs).
- **Sichtbarkeit:** Die Triage zeigt zusätzlich erledigte Items, auch wenn ihr Import nicht
  vollständig war (z. B. ein verworfener Import-Fehler bleibt auffindbar).
- **Beschreibung:** Vorbelegung höchstens 100 000 Zeichen, sonst gekürzt mit «(gekürzt)». Bilder
  werden nicht übernommen, Links nur http, https, mailto.

---

## E10: ClickUp-Details Schritt 5 (2026-11-03)

Aus der Umsetzung und den Reviews von Schritt 5. Ergänzt E2, E9 und Schritt 5 der
Spezifikation. API-Fakten geprüft am 2026-11-03 in developer.clickup.com, echter Test beim
Inhaber am 2026-11-03 erfolgreich (siehe `STATUS.md`).

- **Beschreibung** geht als `markdown_content` an «Create Task», nicht als `markdown_description`
  (Runde 1, Punkt 9). Die Spezifikation nennt noch `markdown_description`.
- **Token des Handelnden** (E2): Task, Anhänge und Kommentar laufen mit dem ClickUp-Token dessen,
  der «Task erstellen» klickt, auch wenn ein Admin für das Item eines anderen handelt. Er steht
  in `processed_by`, der Upload-Job nutzt dessen Token. «Fehlende Anhänge erneut hochladen» setzt
  `processed_by` auf den, der erneut klickt. Listen und Mitglieder auf der Triage-Seite sind die
  des Handelnden.
- **Vorbelegung:** Ziel-List aus der ersten passenden Regel, sonst `last_clickup_list_id` des
  Handelnden, jeweils nur, wenn er die List sieht. Zuständige aus der Regel, sonst die
  `clickup_member_id` des Postfach-Inhabers. Die Tabelle «Eingang» zeigt weiterhin die letzte List
  des Inhabers.
- **Due Date** als 12:00 Europe/Zurich in Millisekunden mit `due_date_time=false`, damit in jeder
  Zeitzone derselbe Tag erscheint. Schnellwahl Heute, Morgen, in 1 Woche nach Zürcher Datum.
- **Priorität** optional: Dringend 1, Hoch 2, Normal 3, Niedrig 4, sonst nicht gesendet.
- **Kommentar** (Vorgabe an) als reiner Text, `notify_all=false`:
  «Von: {Name} <{Adresse}>», «Empfangen: {TT.MM.JJJJ HH:MM Zürich}», «In Outlook: {web_link}» (nur
  https), «Erstellt über ClickUp-Intake von {Handelnder}». Höchstens einmal je Task
  (`clickup_comment_added_at`). Der Wunsch steht am Item (`clickup_comment_requested`), nicht nur
  im Job, damit ein erneuter Upload einen gescheiterten Kommentar nachholt.
- **Ablauf «Task erstellen»:** Sperre je Item (dieselbe wie beim Weiterleiten), Übergang nach
  `creating` mit Auswahl der Anhänge (nur `isUploadable()`), Task synchron, dann Upload-Job.
  Alle Uploads und der Kommentar durch: `created` und «ClickUp ✓» (R10). Sonst `failed` mit
  `clickup_task_id` (Teilerfolg), der Text nennt die fehlenden Anhänge. Scheitert «Create Task»:
  `failed` ohne Task, nach Verbindungsabbruch, 503 oder 504 mit «Unklar, ob der Task angelegt
  wurde. Bitte zuerst in ClickUp prüfen.». POST wird bei 5xx nie wiederholt, bei 429 schon.
- **Hängende Items:** `intake:poll` macht Items, die länger als 15 Minuten in `creating` stehen,
  zu `failed` (mit Task «Der Upload wurde abgebrochen.», ohne Task «Die Task-Erstellung wurde
  abgebrochen. Bitte in ClickUp prüfen.»). Ein Upload-Job, der scheitert oder sein Zeitlimit
  erreicht, hinterlässt ebenfalls einen Teilerfolg.
- **Verbinden:** OAuth mit `state` in der Session. Der Token wird erst gespeichert, wenn
  `GET /user` und `GET /team` gelingen und der App-Workspace dabei ist, sonst wird mit deutschem
  Text abgelehnt. Solange kein Workspace gewählt ist, können nur Admins verbinden (sie wählen ihn
  danach auf der Seite «ClickUp» aus den Workspaces ihres Kontos).
- **Code-Tausch bewusst per Query-String** (`client_id`, `client_secret`, `code`), wie die
  Beispiele der ClickUp-Doku, im echten Test bestätigt. Das Secret steht damit in der URL des
  ausgehenden Aufrufs. Vertretbar, weil die App direkt per TLS mit ClickUp spricht und auf vps06
  kein protokollierender Proxy dazwischen liegt.
- **Redirect-URL:** `http://localhost:8001/clickup/callback` funktioniert lokal (ClickUp prüft
  nur Domain und Port der eingetragenen Adresse). Produktion: die Adresse aus `APP_URL`.
- **401** setzt die ClickUp-Verbindung auf «unterbrochen» und löscht den Token, aber nur den
  abgelehnten (ein inzwischen neu verbundener bleibt). «Trennen» löscht den Token,
  `clickup_member_id` bleibt für die Vorbelegung.
- **Rate-Limit** 100 Requests pro Minute je Token im Cache (Schlüssel ist ein Hash des Tokens),
  festes Fenster von einer Minute. Prüfen und Zählen in einem Schritt (`hit()` zählt atomar und
  liefert den neuen Stand). Im Web-Request wird höchstens 5 Sekunden auf einen freien Platz
  gewartet, «Task erstellen» bricht dann vor jeder Änderung mit «ClickUp ist ausgelastet, bitte
  gleich erneut versuchen.» ab. Im Job bis 60 Sekunden. 429 von ClickUp wartet nach
  `X-RateLimit-Reset` bzw. `Retry-After`, höchstens 60 Sekunden.
- **Cache:** Hierarchie und Mitglieder 1 Stunde je User und Workspace. «ClickUp-Listen neu laden»
  darf jeder, es leert nur den eigenen Cache. «Task erstellen» liest das Label der List nur aus
  dem Cache und lädt die Hierarchie nie im Request.
- **Unklare Uploads und Kommentare** (Verbindungsabbruch, 503, 504) werden markiert und vor dem
  erneuten Senden mit ClickUp abgeglichen: Anhänge über `GET /task/{id}` nach Name und Grösse,
  der Kommentar über `GET /task/{id}/comment` nach dem eigenen Text (ohne die letzte Zeile, die
  den Handelnden nennt). Gefunden gilt als erledigt, sonst wird gesendet. Scheitert der
  Abgleich, wird der Eintrag übersprungen und im Fehlertext genannt.
- **Zeitbudget des Upload-Jobs:** Vor jedem weiteren Anhang wird geprüft, ob Upload-Zeitlimit
  (120 s) und Reserve (30 s) noch in die 300 s des Jobs passen. Sonst reiht der Job einen
  Folgejob für den Rest ein und endet ohne Timeout (eindeutig nur bis zum Start, damit der
  Folgejob eingereiht werden kann). Scheitert der Job trotzdem, nennt der Fehlertext die noch
  fehlenden Anhänge.
- **Task angelegt, aber nicht gespeichert:** Task-ID und URL ins Log, das Item wird `failed` ohne
  Task mit «Der Task wurde angelegt, konnte aber nicht gespeichert werden: {URL}», kein Upload.
- **Kommentartext:** Steuer- und Bidi-Zeichen in Absendername und Adresse werden zu Leerzeichen
  (wie bei Anhangnamen), Name höchstens 100, Adresse 254 Zeichen. So entsteht keine zusätzliche
  Zeile.
- **Trennen und Deaktivieren** löschen den Token in der App. ClickUp-Tokens laufen nicht ab, darum
  der Hinweis «In ClickUp unter Settings, Apps die Freigabe ebenfalls entfernen.».
- **Regeln:** neue Regeln kommen ans Ende, Reihenfolge per Ziehen. Domain ohne Schema und Pfad
  (führendes `@` erlaubt), Adresse als E-Mail geprüft. List und Zuständige aus der Verbindung des
  Admins, das Label wird mit der Regel gespeichert.
- **Validierung:** deutsche Meldungen ohne Feldnamen in `lang/de/validation.php` (Filament zeigt
  sie unter dem Feld).

---

## E11: Abruf jede Minute, Eingang aktualisiert sich (2026-11-04)

Auf Wunsch des Inhabers: `intake:poll` jede Minute statt alle 2 Minuten (Spezifikation Schritt 3),
die Liste «Eingang» lädt sich alle 30 Sekunden selbst neu. Eine markierte Mail erscheint damit nach
höchstens rund einer Minute. Bei 5 Personen 5 kurze Graph-Abfragen pro Minute, unkritisch. Pro User
läuft weiterhin nur ein Abruf gleichzeitig (`ShouldBeUnique`). Push über Graph-Webhooks wurde
verworfen: deutlich mehr Aufbau und Betrieb für wenig Gewinn.

---

## Weitere Punkte aus Runde 1

| # | Punkt | Stand |
|---|---|---|
| 1 | Fehlende Graph-Permissions | gelöst durch E1 (delegiert, inkl. `Mail.Send`, `MailboxSettings.ReadWrite`) |
| 2 | Theme inkompatibel | gelöst durch E5 |
| 3 | Supervisor oder systemd | entschieden: systemd (E4) |
| 4 | Wer darf sich anmelden | gelöst durch Gruppenzuweisung (E3) |
| 5 | Teilerfolg bei «Task erstellen» | umgesetzt (E10): `failed` mit `clickup_task_id`, Aktion «Fehlende Anhänge erneut hochladen» |
| 6 | Erneut markieren nach «Verwerfen» | angenommen: Item zurück auf `pending` |
| 7 | Kategorien | angenommen: «ClickUp» wird durch «ClickUp ✓» bzw. «Support ✓» ersetzt, andere bleiben |
| 8 | Inline-Bilder im sandboxed iframe | verifiziert, siehe E9 |
| 9 | ClickUp-Feldname Beschreibung | `markdown_content` (E10), im echten Test verwendet |
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
