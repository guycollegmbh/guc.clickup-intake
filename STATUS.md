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
  Zugangsdaten der Entra-App aus `settings` (verschlüsselt) oder `.env` als Fallback.
- Delegierte Scopes beim Login angefordert, Refresh-Token verschlüsselt am User.
- Theme: Primary `#00AEEF`, Dark `#231F20`, Source Sans 3 lokal, Radius 0, keine Schatten.
- `users` nach E1 bis E3 erweitert, `settings`-Tabelle. Passwort-Spalten entfernt.
- `intake:make-admin`, CI mit Pint, Pest, `composer audit`.

## Offen und bekannt

- Ohne Setup-Assistent (1b) geht Login nur mit manuell eingetragener App-Registration in `.env`.
- Im Browser noch nicht gegen einen echten Tenant geprüft.
- `Setting::get` liest je Aufruf aus der DB, beim Login drei Abfragen. Unkritisch, bei Bedarf
  cachen.
