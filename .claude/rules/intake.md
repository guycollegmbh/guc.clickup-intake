# Harte Regeln: guc.clickup-intake

Quelle: `docs/AGENT_PROMPT.md`. Gelten für jeden Code in diesem Repo. Eine Abweichung braucht einen
Entscheid in `docs/01-decisions.md`, nicht nur eine Änderung im Code.

## Abgrenzung

- **R1 Nur ClickUp-Tasks.** Die App erstellt nie Tickets und kennt das Ticketsystem guycolle.help
  nicht (kein Code, keine API, keine geteilte DB). Der einzige Weg dorthin ist die Weiterleitung der
  Mail per Graph `forward` an `INTAKE_SUPPORT_ADDRESS`.
- **R2 Keine KI in V1.** Vorbelegungen (List, Assignees) ausschliesslich über `intake_rules` und den
  User. Kein LLM-Aufruf, kein Klassifikator.
- **R3 Nicht in V1:** Tasks in mehreren Lists, an bestehenden Task anhängen, Shared Mailboxes,
  Outlook-Add-in (Phase C).

## Zugangspunkte

- **R4 Ein Client je Dienst.** Microsoft Graph nur über den Graph-Client, ClickUp nur über den
  ClickUp-Client (`app/Services/...`). Kein `Http::` und kein SDK-Aufruf daneben. Retry, Backoff
  (`Retry-After` bei 429 und 503) und das ClickUp-Limit (100 Requests pro Minute pro Token) liegen
  ausschliesslich in diesen Clients.
- **R5 Streamen statt laden.** Attachment-Inhalte von Graph werden gestreamt auf Disk geschrieben,
  nie komplett in den Speicher.

## Daten und Zustand

- **R6 Keine Mail verschwindet stillschweigend.** Jeder Fehler im Import oder in der Verarbeitung
  endet in Status `failed` mit Fehlertext. `failed` und `pending` werden nie automatisch gelöscht.
- **R7 Dedupe** über `(mailbox_upn, internet_message_id)` mit eindeutigem Index.
  `graph_message_id` ist veränderlich (Move) und nur Referenz, nie Schlüssel.
- **R8 Datei und Zeile sind eine Einheit.** Erst `intake_attachments`-Zeile ohne `storage_path`,
  dann Datei schreiben, dann `storage_path` setzen. Scheitert das Schreiben: Zeile mit `error`.
  Löschen nur über das Item (Model-Event löscht `intake/{item_id}`). Pfade aus IDs, nie aus
  Dateinamen. `intake:orphans` muss 0 melden.
- **R9 Retention:** nur `created`, `forwarded`, `discarded` nach 30 Tagen, inkl. Dateien.
- **R10 Outlook-Kategorie folgt dem Ergebnis.** «ClickUp ✓» erst, wenn Task und alle Uploads durch
  sind (`creating` → `created`). «Support ✓» erst nach erfolgreichem Forward. Bei `discarded` wird
  «ClickUp» entfernt.

## Zugriff und Darstellung

- **R11 Login nur Entra ID** (Socialite, nur GUYCOLLE-Tenant). Kein lokales Passwort.
- **R12 Sichtbarkeit über Policies.** `member` sieht und bearbeitet nur eigene Items, `admin` alle.
  Resource «Regeln» nur `admin`. Gilt auch für Livewire-Methoden, Filament-Aktionen, Bulk-Aktionen
  und die Route für Inline-Bilder und Anhänge.
- **R13 Mail-HTML nur im sandboxed iframe** (`sandbox=""`, CSP ohne Script). Nie `{!! !!}` mit
  Mail-Inhalt im Panel.
- **R14 Weiterleiten** nur nach Bestätigungsdialog und nur an die konfigurierte Support-Adresse.
  Keine frei eingebbare Empfängeradresse.

## Konventionen

- **R15 Sprache:** UI Deutsch (Schweizer Hochdeutsch, ss statt ß, keine Gedankenstriche, keine
  Semikolons). Code, Kommentare, Commits, Branches Englisch. Conventional Commits.
- **R16 Pakete:** nur die im Stack genannten. Jedes weitere Paket braucht Freigabe.
- **R17 Wartbarkeit vor Eleganz.** Keine Abstraktion ohne zweiten Anwendungsfall.
