# STATUS

Stand 2026-11-02, Branch `develop`.

| Schritt | Inhalt | Stand |
|---|---|---|
| 0 | Konzept-Check, `docs/01-decisions.md` | erledigt, freigegeben |
| 1 | Scaffold, Theme, Microsoft-Login, CI | erledigt |
| 1b | Setup-Assistent «Mit Microsoft 365 verbinden» (`intake:install`) | erledigt, Einrichtung im Tenant ok, Login-Test offen |
| 2 | Datenmodell Intake | offen |
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

- `app/Microsoft/GraphClient.php`: einziger Graph-Zugang (R4), an ein Token gebunden, Retry bei
  429, 503, 504 mit `Retry-After`. `GraphException` ohne Antworttext.
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

## Offen und bekannt

- Erster Lauf gegen den echten Tenant steht aus.
- Warnung vor Ablauf des Secrets und Admin-Seite «Microsoft 365» folgen mit Schritt 4 bzw. 7.
- Im Browser noch nicht gegen einen echten Tenant geprüft.
- `.env.production.example` bzw. Deploy-Checkliste folgt in Schritt 7 (`docs/02-setup.md`).
