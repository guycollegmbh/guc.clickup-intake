# Startprompt für Claude Code

Kopiere den folgenden Block als erste Nachricht in Claude Code.

---

Du baust `guc.clickup-intake`, ein internes Tool der GUYCOLLE GmbH: Outlook-Mails, die mit der Kategorie «ClickUp» markiert sind, werden in einer Triage-Queue zu ClickUp-Tasks.

Die vollständige Spezifikation liegt in `docs/AGENT_PROMPT.md`. Lies sie komplett, bevor du etwas tust.

Beginne mit **Schritt 0 (Konzept-Check)**. Schreibe das Ergebnis nach `docs/01-decisions.md`. Schritt 0 ist rein lesend und planend, es wird kein Code erzeugt.

Stoppe nach Schritt 0 und melde dich mit:

1. Den fünf Entscheiden aus Schritt 0, je mit Empfehlung in einem Satz.
2. Allem, was dir an der Spezifikation widersprüchlich oder unklar vorkommt.

Erst nach meiner Freigabe geht es mit Schritt 1 weiter. Arbeite auf Branch `develop`, `main` bleibt bis zum ersten Deploy unberührt.

---

## Nach der Freigabe

Zweite Nachricht an Claude Code:

> Freigabe erteilt mit folgenden Anpassungen: [Anpassungen]. Setze Schritte 1 bis 7 aus `docs/AGENT_PROMPT.md` um. Zeige mir nach jedem Schritt kurz den Stand, bevor du weitermachst.

Phase C (Outlook-Button) wird als eigener Lauf gestartet, nachdem V1 einige Wochen im Betrieb ist.
