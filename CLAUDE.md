# CLAUDE.md: guc.clickup-intake

Internes Tool der GUYCOLLE GmbH. Outlook-Mails mit der Kategorie «ClickUp» werden per Microsoft
Graph in eine Triage-Queue geholt und dort zu ClickUp-Tasks.

- Spezifikation: `docs/AGENT_PROMPT.md` (verbindlich)
- Entscheide: `docs/01-decisions.md` (Schritt 0, nach Freigabe verbindlich)
- Harte Regeln: `.claude/rules/intake.md`
- Arbeitsstand: `STATUS.md` (ab Schritt 1)

## Stack

Vorgabe gemäss Spezifikation, Bestätigung in Schritt 0: Laravel + Filament, SQLite, Redis (Queue,
Cache), Pest, GitHub Actions, Theme `guycollegmbh/guc.filament-theme`, Hosting IONOS-VPS mit Caddy.

## Konventionen

- UI Deutsch (Schweizer Hochdeutsch, ss statt ß, keine Gedankenstriche, keine Semikolons).
- Code, Kommentare, Commits, Branches Englisch. Conventional Commits.
- Gearbeitet wird auf `develop`. `main` bleibt bis zum ersten Deploy unberührt.
- Keine KI-Komponente in V1. Keine Kopplung zum Ticketsystem guycolle.help.

## Agenten (`.claude/agents/`)

Umsetzung:

- `fullstack-developer`: Code und Tests für die Schritte 2 bis 6 und Befunde. Committet nicht.
- `devops-engineer`: Commits, Pull Requests, CI, `docs/02-setup.md`. Nie direkt auf dem Server,
  Deploy nur auf ausdrückliche Anweisung.

Review (lesen nur, liefern belegte Befunde):

- `code-reviewer`: Korrektheit, Fehlerwege, Jobs, Tests. Nach jedem Schritt und vor jedem Merge.
- `security-reviewer`: Login, Policies, fremdes Mail-HTML, Anhänge, Graph, Geheimnisse.
  Nach Änderungen an diesen Bereichen und vor jedem Deploy.
- `architecture-guardian`: Spezifikation, Entscheide, Regeln, Doku-Drift, Scope.
  Nach jedem Schritt der Spezifikation und vor grösseren Änderungen.

Ablauf je Schritt: `fullstack-developer` setzt um (Tests und Pint grün), die drei Reviews laufen
parallel, `fullstack-developer` behebt Befunde mit Schweregrad kritisch und hoch,
`devops-engineer` committet, Stand in `STATUS.md`.
