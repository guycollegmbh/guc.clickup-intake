# STATUS

Stand 2026-11-02, Branch `develop`.

| Schritt | Inhalt | Stand |
|---|---|---|
| 0 | Konzept-Check, `docs/01-decisions.md` | erledigt, freigegeben |
| 1 | Scaffold, Theme, Microsoft-Login, CI | erledigt |
| 1b | Setup-Assistent «Mit Microsoft 365 verbinden» (`intake:install`) | offen |
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

## Offen und bekannt

- Ohne Setup-Assistent (1b) ist kein Login möglich.
- Im Browser noch nicht gegen einen echten Tenant geprüft.
- `.env.production.example` bzw. Deploy-Checkliste folgt in Schritt 7 (`docs/02-setup.md`).
