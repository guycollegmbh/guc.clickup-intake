# 02: Einrichtung und Betrieb

Stand 2026-11-03. Produktion: vps06 (85.215.157.193), `https://intake.guycolle.services`.
Auf demselben Server laufen guc.dns-manager, guc.cronman und guc.bexio-abgleich. Jede Änderung an
Caddy, PHP-FPM oder Redis betrifft alle Apps: Konfiguration vorher sichern und prüfen.

## Überblick

| Teil | Wert |
|---|---|
| Verzeichnis | `/var/www/clickup-intake` (Git-Checkout von `main`, Besitzer `guycolle`, Gruppe `clickupintake`) |
| System-User | `clickupintake` (kein Login), PHP-FPM, Worker und Scheduler laufen unter diesem User |
| PHP-FPM | Pool `/etc/php/8.4/fpm/pool.d/clickup-intake.conf`, Socket `php8.4-fpm-clickup-intake.sock`, Zeitlimit 300 s |
| Caddy | Block `intake.guycolle.services` in `/etc/caddy/Caddyfile`, Zeitlimit 300 s |
| Queue | Redis, DB 4 (Queue) und 5 (Cache), Prefix `clickup_intake_` |
| Worker | `clickup-intake-worker.service`, Log `/var/log/clickup-intake/worker.log` |
| Scheduler | `/etc/cron.d/clickup-intake` (jede Minute `schedule:run`): `intake:poll` jede Minute, `intake:prune` täglich 03:30 UTC |
| Datenbank | SQLite `database/database.sqlite` |
| Anhänge | `storage/app/private/intake/{item}/{anhang}` |
| App-Log | `storage/logs/laravel-YYYY-MM-DD.log` (Level warning) |
| GitHub | Deploy-Key `~/.ssh/id_ed25519_clickup_intake` (nur lesen), Host-Alias `github-clickup-intake` |

Vorlagen aller Server-Dateien liegen in `deploy/`.

## Deploy

Code kommt über Pull Request von `develop` nach `main` (CI grün). Dann auf dem Server:

```bash
ssh guycolle@85.215.157.193
/var/www/clickup-intake/deploy/deploy.sh
```

Das Skript holt `main`, schaltet in den Wartungsmodus, installiert Abhängigkeiten, baut die Assets,
migriert, cacht, lädt PHP-FPM neu und startet den Worker neu. Scheitert ein Schritt, bleibt die App
im Wartungsmodus: Ursache beheben und erneut starten oder `sudo -u clickupintake php artisan up`.

## Ersteinrichtung (wie am 2026-11-03 ausgeführt)

1. System-User und Gruppe: `useradd --system --user-group --shell /usr/sbin/nologin clickupintake`,
   `guycolle` und `gucbackup` in die Gruppe `clickupintake`.
2. Deploy-Key erzeugen, in GitHub als Deploy-Key (nur lesen) hinterlegen, Host-Alias in
   `~/.ssh/config`. Der bestehende Eintrag `github.com` gehört zu guc.dns-manager und bleibt.
3. `git clone git@github-clickup-intake:guycollegmbh/guc.clickup-intake.git /var/www/clickup-intake`,
   Wurzel `2751` (Caddy muss `public/` erreichen), `storage`, `database`, `bootstrap/cache` nur für die
   Gruppe.
4. `.env` aus `.env.example` mit: `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_URL=https://intake.guycolle.services`, `LOG_STACK=daily`, `LOG_LEVEL=warning`,
   `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_DB=4`, `REDIS_CACHE_DB=5`,
   `REDIS_PREFIX=clickup_intake_`, `SESSION_SECURE_COOKIE=true`,
   `INTAKE_SUPPORT_ADDRESS=helpdesk@guycolle.com`. Rechte `640`, Gruppe `clickupintake`.
   Microsoft- und ClickUp-Zugangsdaten stehen **nicht** in `.env` (E1, E2).
5. `composer install --no-dev`, `npm ci && npm run build`, `php artisan key:generate`,
   `migrate`, `optimize`, `filament:optimize`.
6. PHP-FPM-Pool installieren, `php-fpm8.4 -t`, `systemctl reload php8.4-fpm`.
7. Worker-Unit installieren, `systemctl enable --now clickup-intake-worker`, Cron-Datei installieren.
8. Caddyfile sichern (`Caddyfile.bak.<datum>`), Block anhängen, `caddy validate`, `systemctl reload caddy`.
9. Microsoft 365 verbinden (unten), danach ClickUp (unten).

## Microsoft 365

```bash
cd /var/www/clickup-intake && sudo -u clickupintake php artisan intake:install
```

Ein M365-Admin (Globaler Administrator oder Cloudanwendungsadministrator) meldet sich per
Gerätecode an. «Im Namen der Organisation zustimmen» nicht ankreuzen. Der Befehl legt die
App-Registration «GUYCOLLE ClickUp-Intake» (Produktion, lokal mit Suffix «(local)») mit
Zustimmung für alle Benutzer an und macht den Admin zum ersten App-Admin. Danach im Entra-Portal
der Enterprise-App die Gruppe zuweisen (Benutzer und Gruppen), sonst kann sich niemand anmelden.

- Secret läuft nach 24 Monaten ab (Produktion: 03.11.2028). Erneuern:
  `sudo -u clickupintake php artisan intake:install --renew-secret` (neues Secret wird zuerst
  gespeichert, dann werden die alten entfernt).
- Gerätecode gesperrt: `--manual` (Werte einer von Hand angelegten Registration).
- Kategorien in einem Postfach reparieren: `php artisan intake:setup-categories {upn?}`.

## ClickUp

1. Ein Workspace-Admin legt in ClickUp unter Settings, Apps eine OAuth-App an. Redirect-URLs:
   `intake.guycolle.services` (und `localhost:8001` für lokale Tests). ClickUp prüft nur Domain
   und Port.
2. In der App als Admin die Seite «ClickUp» öffnen: Client-ID und Secret eintragen (das Secret wird
   verschlüsselt gespeichert und nie wieder angezeigt), eigenes Konto verbinden, Workspace wählen.
3. Jeder Benutzer verbindet sein ClickUp-Konto einmal über das Banner im Panel.

## Benutzer

- Zugang: Mitglied der Gruppe, die der Enterprise-App zugewiesen ist. Der User entsteht beim ersten
  Login als Mitglied. Rollen und Aktivierung unter «Benutzer» im Panel.
- Notweg: `sudo -u clickupintake php artisan intake:make-admin {email}` (oder `--oid=`).
- **Offboarding:** in Entra aus der Gruppe entfernen, in der App unter «Benutzer» deaktivieren
  (löscht Microsoft- und ClickUp-Tokens). In ClickUp unter Settings, Apps die Freigabe der Person
  ebenfalls entfernen, ClickUp-Tokens laufen nicht ab.

## Backup

Der Backup-Server (guc.backup) sichert die App. Einmalig dort erfassen:

- Server 85.215.157.193, Pfad `/var/www/clickup-intake`, Datenbank SQLite `database/database.sqlite`
  (Sicherung mit `sqlite3 … ".backup …"`, nie direkt kopieren), dazu `storage/app/private/intake`.
- `gucbackup` ist Mitglied der Gruppe `clickupintake` und kann beides lesen.
- `.env` enthält `APP_KEY`: Ohne ihn sind die gespeicherten Microsoft- und ClickUp-Zugangsdaten nicht
  lesbar. Bei einem Restore auf einen neuen Server `APP_KEY` mitnehmen oder `intake:install` und die
  Seite «ClickUp» neu durchlaufen.

## Überwachung und Fehlersuche

- `php artisan intake:prune` endet mit Fehler bei verwaisten Dateien oder hängenden Items (Outlook-
  Kategorie nicht gesetzt). Ausgabe im Schedule-Log.
- `php artisan intake:orphans` muss `Orphans: 0` melden.
- Benutzer mit unterbrochener Microsoft- oder ClickUp-Verbindung zeigt die Liste «Benutzer»
  (Status, letzter Abruf).
- Tinker auf dem Server: `sudo -u clickupintake HOME=/var/www/clickup-intake/storage/tmp php artisan tinker`.
- Nicht gesichert und bekannt: Redis läuft ohne Passwort, nur auf 127.0.0.1 (gilt für alle Apps
  auf vps06).

## Lokal

Siehe `CLAUDE.md`. Lokal eine eigene App-Registration «(local)» mit `APP_URL=http://localhost:8001`
und `INTAKE_SUPPORT_ADDRESS` auf die eigene Adresse, damit Tests nicht im Helpdesk landen. Lokal und
Produktion lesen dasselbe Postfach: lokal keinen Scheduler laufen lassen, wenn die Produktion läuft.
