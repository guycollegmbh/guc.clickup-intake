# 01: Entscheide (Schritt 0, Konzept-Check)

Stand 2026-11-02. Status: **Vorschlag, wartet auf Freigabe.** Nach Freigabe verbindlich (siehe
`.claude/rules/intake.md`).

Grundlage: `docs/AGENT_PROMPT.md`, dazu die Nachbar-Repos `guc.filament-theme`, `guc.quotebot`
(Belegablage mit Graph App-only), `guc.cronman` und das Inventar in `guc.ansible`.

---

## E1: Graph-Berechtigung

### Optionen

| | A: Application Permission + Zugriffsbeschränkung | B: Delegated pro User |
|---|---|---|
| Token | Ein Client-Credentials-Token für alle Mailboxen | Refresh-Token je User, verschlüsselt gespeichert |
| Ausfall | Nur bei abgelaufenem Secret oder Zertifikat (einmal, sichtbar) | Still je User nach Inaktivität, Passwortwechsel, CA-Richtlinie |
| Poll | Ein Job je aktivem User, gleiches Token | Ein Job je User mit dessen Token, Refresh-Logik |
| Reichweite | Ohne Beschränkung alle Mailboxen im Tenant, darum Beschränkung Pflicht | Nur die eigene Mailbox |
| Aufwand | Einmalig Exchange-PowerShell | Token-Lifecycle im Code, Re-Consent-Flows |
| Bewährt im Tenant | Ja, `guc.quotebot` Belegablage | Nein |

### Benötigte Permissions (Korrektur zur Spezifikation)

Die Spezifikation nennt nur `Mail.ReadWrite`. Für den vollen Funktionsumfang braucht es:

| Permission | Wofür |
|---|---|
| `Mail.ReadWrite` | Mails und Anhänge lesen, Kategorien auf der Mail setzen |
| `Mail.Send` | `POST /messages/{id}/forward` («An Support weiterleiten») |
| `MailboxSettings.ReadWrite` | `outlook/masterCategories` für `intake:setup-categories` |

Alle drei als Application Permission, alle drei von derselben Zugriffsbeschränkung erfasst.
Alternativ entfällt `MailboxSettings.ReadWrite`, wenn die drei Kategorien einmalig von Hand oder
per PowerShell angelegt werden. Dann wird `intake:setup-categories` zur reinen Prüfung.

Eigene App-Registration «guc.clickup-intake», nicht die der Belegablage mitbenutzen. Getrennte
Secrets, getrennte Gruppen, getrennter Widerruf.

### PowerShell: Application Access Policy

```powershell
Connect-ExchangeOnline

# Mail-aktivierte Sicherheitsgruppe «ClickUp-Intake» muss bestehen, Mitglieder = Team-Postfächer.
New-ApplicationAccessPolicy `
  -AppId "<INTAKE_CLIENT_ID>" `
  -PolicyScopeGroupId "clickup-intake@guycolle.ch" `
  -AccessRight RestrictAccess `
  -Description "guc.clickup-intake: nur Postfaecher der Gruppe ClickUp-Intake"

# Positiv- und Negativtest (Auswertung kann bis 30 Minuten dauern)
Test-ApplicationAccessPolicy -Identity "<mitglied>@guycolle.ch"      -AppId "<INTAKE_CLIENT_ID>"
Test-ApplicationAccessPolicy -Identity "<nicht-mitglied>@guycolle.ch" -AppId "<INTAKE_CLIENT_ID>"
```

Erwartet: `Granted` für Mitglieder, `Denied` für alle anderen. Gilt für `Mail.*` und
`MailboxSettings.*`.

Hinweis: Microsoft empfiehlt für neue Setups «RBAC for Applications» in Exchange Online
(`New-ManagementScope` plus `New-ManagementRoleAssignment` mit Rollen wie
`Application Mail.ReadWrite`, `Application Mail.Send`). Dann werden die Permissions **nicht** in
Entra erteilt, sonst gilt die Vereinigung beider Wege und die Beschränkung ist wirkungslos. Die
Access Policy ist im Tenant bewährt und reicht für V1. In `docs/02-setup.md` dokumentiere ich
beide Wege mit der Access Policy als Standard.

### Empfehlung

**A**, mit eigener App-Registration, den drei Permissions oben und Application Access Policy auf
die Gruppe «ClickUp-Intake». Kein Token-Lifecycle je User, kein stilles Ausfallen.

---

## E2: ClickUp-Auth

### Optionen

| | A: Personal API Token eines Service-Users | B: OAuth je User |
|---|---|---|
| «Erstellt von» | Service-User | echter User |
| Aufwand | Token in `.env`, fertig | OAuth-App, Token je User, Refresh, Re-Consent |
| Rate-Limit | 100 Requests pro Minute für alle zusammen | je User |
| Ausfall | Token widerrufen oder Service-User deaktiviert: alle betroffen, sofort sichtbar | je User still |
| Kosten | Service-User belegt einen ClickUp-Sitz | keine |

Folgen von A, die das Team kennen muss:
- «Erstellt von» und Benachrichtigungen «X hat einen Task erstellt» zeigen den Service-User.
- Wer den Task ausgelöst hat, steht im Kommentar (Checkbox «Absender und Mail-Link als Kommentar
  anfügen», ergänzt um «Erstellt über ClickUp-Intake von {name}») und in `processed_by`.
- Filter «von mir erstellt» in ClickUp funktioniert nicht mehr für diese Tasks.
- 100 Requests pro Minute reichen: ein Task mit 5 Anhängen braucht etwa 7 Requests.

### Empfehlung

**A** für V1, mit dem Kommentar-Zusatz oben als Nachweis des Auslösers. Offene Frage an dich:
Ist «Erstellt von = Service-User» im Team ein Problem, und gibt es einen freien ClickUp-Sitz oder
einen bestehenden technischen User?

---

## E3: Login und Rollen

### Login

Entra ID SSO über Socialite (`socialiteproviders/microsoft-azure`) mit Tenant-ID statt `common`,
im Callback zusätzlich `tid` gegen `AZURE_TENANT_ID` geprüft. Kein lokales Passwort, keine
Passwort-Reset-Routen. Abgleich über `entra_object_id` (`oid`), nicht über die E-Mail.

Separate App-Registration für den Login (nur `openid profile email User.Read`, delegated) oder
dieselbe wie für Graph: **getrennt**, damit ein Leck des Login-Secrets keinen Mailzugriff gibt.

### Wer darf rein

Die Spezifikation beschränkt nur auf den Tenant. Damit könnte sich jeder Tenant-User anmelden und
würde ein User mit Mailbox. Vorschlag: Neue User werden beim ersten Login mit `active = false`
angelegt, ausser sie sind Mitglied der Gruppe «ClickUp-Intake» (Abfrage per Gruppen-Claim
`groups` im ID-Token, ohne zusätzliche Permission). Nur aktive User werden gepollt und dürfen
ins Panel. Das hält Login-Zugang und Mailbox-Zugriff (E1) an derselben Gruppe.

### Rollen

| | Spalte `role` | Entra-Gruppe «ClickUp-Intake-Admins» |
|---|---|---|
| Pflege | Im Panel durch Admin | Im Entra-Admin-Center |
| Erster Admin | `php artisan intake:make-admin {email}` | Gruppenmitgliedschaft |
| Aufwand | gering | zweite Gruppe, Claim-Auswertung |

### Empfehlung

Socialite mit Tenant-Bindung, Zugang über die Gruppe «ClickUp-Intake», Rollen als **Spalte**,
erster Admin per Artisan-Command. `role` ist nicht mass-assignable.

---

## E4: Betrieb

### Server

Kein neuer Server (Vorgabe). Kandidaten aus `guc.ansible/inventory/production.yml`:

| Host | Apps | Caddy | Redis | Bemerkung |
|---|---|---|---|---|
| **vps06** | guc.dns, guc.cronman | ja | ja (cronman) | interne Tools, gleicher Stack (PHP 8.4, SQLite, Redis) |
| vps07 | guc.quotebot (bexio.guycolle.services) | ja | unbekannt | hat bereits Graph-Mailzugriff, Fachanwendung mit eigenem Betriebsregime |

### Subdomain

`intake.guycolle.services`, passend zu `cron.`, `dns.`, `bexio.guycolle.services`. DNS-Eintrag über
guc.dns, TLS automatisch über Caddy. Danach in `guc.ansible` unter `urls` eintragen.

### Prozesse

- Queue-Worker: Die Spezifikation nennt Supervisor. Auf vps06 laufen Worker als **systemd-Unit**
  (`cronman-worker.service`). Vorschlag: ebenfalls systemd (`clickup-intake-worker.service`),
  damit ein Host nur eine Prozessverwaltung hat.
- Scheduler: Cron-Eintrag `* * * * * php artisan schedule:run` unter dem App-User.
- Redis: bestehende Instanz auf vps06, eigene DB-Nummer und eigener Prefix.
- Backup: SQLite und `storage/app/intake` in den bestehenden Backup-Lauf (guc.backup auf vps05).

### Empfehlung

**vps06**, `intake.guycolle.services`, Worker als systemd-Unit statt Supervisor, Cron für den
Scheduler, Redis mit eigener DB-Nummer.

---

## E5: Filament-Version und Theme

### Befund

`guycollegmbh/filament-theme` (aktuell v1.1.0) verlangt `filament/filament: ^3.0` und
`illuminate/support: ^12.0`. Es ist **nicht kompatibel** mit Laravel 13 und Filament 5. Es gibt
keinen Branch für Filament 4 oder 5. Das Paket selbst ist klein: ein Plugin, ein
ServiceProvider, `theme.css`, zwei Blade-Komponenten, Logos (rund 700 Zeilen).

Im Haus laufen bereits Laravel 13 mit Filament 5 (`guc.bexio-abgleich`, `fam.wochenplan`) ohne das
Theme-Paket. `guc.newsletter` als Referenz liegt lokal nicht vor, ich konnte es nicht prüfen.

### Optionen

| | A: Laravel 12 + Filament 3 mit Theme | B: Laravel 13 + Filament 5, Theme zuerst portieren | C: Laravel 13 + Filament 5, Theme lokal nachbauen |
|---|---|---|---|
| Theme | sofort | Paket v2.0 für Filament 5 (eigener Lauf im Theme-Repo) | Farben, Schrift, Radius im PanelProvider plus eigene `theme.css` |
| Lebensdauer | Filament 3 ist zwei Major-Versionen alt, Upgrade 3 → 5 steht bald an | lang | lang |
| Aufwand | gering | mittel (Theme-CSS gegen Filament-5-Markup prüfen) | gering, aber Abweichung vom Designsystem |
| Nutzen für andere Repos | keiner | alle künftigen Filament-5-Apps | keiner |

### Empfehlung

**B**: Laravel 13 und Filament 5, vorher `guc.filament-theme` als v2.0 auf Filament 5 portieren
(eigener Lauf im Theme-Repo, v1.x bleibt für die Filament-3-Apps). Falls das den Start zu stark
verzögert: Start mit C und Wechsel auf das Paket, sobald v2.0 da ist. A widerspricht
«Wartbarkeit vor Eleganz», weil das Upgrade nur verschoben wird.

---

## Widersprüche und Unklarheiten in der Spezifikation

1. **Fehlende Graph-Permissions.** Weiterleiten braucht `Mail.Send`, `masterCategories` braucht
   `MailboxSettings.ReadWrite`. Siehe E1.
2. **Theme inkompatibel** mit der vorgegebenen Laravel- und Filament-Version. Siehe E5.
3. **Supervisor vs. systemd.** Spezifikation nennt Supervisor, der Zielserver nutzt systemd.
   Siehe E4.
4. **Wer darf sich anmelden.** Nur «GUYCOLLE-Tenant» würde jeden Tenant-User zum gepollten User
   machen. Und ein Teammitglied wird erst nach dem ersten Login gepollt. Siehe E3.
5. **Teilerfolg bei «Task erstellen».** Task ist angelegt, ein Upload scheitert endgültig. Status
   `failed` würde einen erneuten Klick auf «Task erstellen» und damit einen zweiten Task nahelegen.
   Vorschlag: Status `failed` mit gesetzter `clickup_task_id`, Aktion «Fehlende Anhänge erneut
   hochladen» statt «Task erstellen», Kategorie bleibt «ClickUp».
6. **Erneut markieren nach «Verwerfen».** Dedupe über `(mailbox_upn, internet_message_id)` blockiert
   den Re-Import, solange das verworfene Item existiert (30 Tage). Wer eine verworfene Mail erneut
   mit «ClickUp» markiert, sieht nichts. Vorschlag: Taucht eine Mail mit Kategorie «ClickUp» auf,
   deren Item `discarded` ist, wird das Item auf `pending` zurückgesetzt.
7. **Kategorien ersetzen oder ergänzen.** Für «ClickUp ✓» und «Support ✓» nicht ausdrücklich
   geregelt. Vorschlag: «ClickUp» wird jeweils ersetzt, andere Kategorien des Users bleiben.
   Sonst importiert der Poll die Mail bei jedem Lauf erneut (Dedupe fängt es ab, kostet aber
   Requests).
8. **Inline-Bilder im sandboxed iframe.** Ein iframe mit `sandbox=""` hat einen opaken Ursprung.
   Requests daraus gelten als cross-site, das Session-Cookie (`SameSite=Lax`) wird
   voraussichtlich nicht mitgesendet und die authentifizierte Route antwortet mit 401.
   Vorschlag: Inline-Bilder über kurzlebige signierte URLs (`URL::temporarySignedRoute`), in
   Schritt 4 im Browser verifizieren.
9. **ClickUp-Feldname.** Die Spezifikation nennt `markdown_description` für `createTask`. In
   Schritt 5 gegen die aktuelle ClickUp-API prüfen (dort heisst das Feld je nach Endpunkt auch
   `markdown_content`).
10. **Retention und Kategorie.** Scheitert das Kategorie-Update nach `created` und wird das Item
    nach 30 Tagen gelöscht, importiert der nächste Poll die Mail erneut. Vorschlag: gescheitertes
    Kategorie-Update als eigener Retry-Job, Item erst löschen, wenn die Kategorie gesetzt ist.
11. **Referenz `guc.newsletter`** liegt lokal nicht vor. Versionen habe ich gegen
    `guc.bexio-abgleich` (Laravel 13.38, Filament 5.11) geprüft.
12. **Grösse von Anhängen.** Keine Obergrenze genannt. Vorschlag: konfigurierbares Limit
    (Standard 50 MB je Anhang), grössere als Link-Hinweis in der Beschreibung und mit `error`
    an der Zeile.
