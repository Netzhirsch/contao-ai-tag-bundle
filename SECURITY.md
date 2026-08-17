# Sicherheit

## Sicherheitslücken melden

**Bitte nicht über öffentliche Issues.** Zwei Wege:

1. **GitHub Security Advisory** (bevorzugt): im Repository unter *Security → Report a
   vulnerability*. Die Meldung ist nur für die Maintainer sichtbar.
2. **E-Mail** an `netzhirsch@netzhirsch.de`, gern mit dem Betreff
   *contao-ai-tag-bundle*.

Hilfreich sind: betroffene Version, Contao- und PHP-Version, ein möglichst kleiner
Weg zur Reproduktion und die Auswirkung, die Sie sehen. Wir bestätigen den Eingang
innerhalb von **5 Werktagen** und melden uns danach mit einer Einschätzung.

Wir bitten um eine koordinierte Veröffentlichung: bis zu **90 Tage** nach Eingang der
Meldung, oder früher, sobald eine Fassung mit der Behebung verfügbar ist. Wer eine
Lücke meldet, wird auf Wunsch im Changelog genannt.

## Unterstützte Fassungen

| Version | Sicherheitskorrekturen |
|---|---|
| 1.x | ja |
| 0.x | nein – bitte auf 1.x aktualisieren |

Korrekturen erscheinen als Patch-Version auf dem regulären Composer-Kanal. Das Bundle
unterstützt Contao 5.3 (LTS) bis 5.7+ und PHP 8.1+; Sicherheitskorrekturen erscheinen
für alle davon unterstützten Zweige gleichzeitig.

## Was dieses Bundle im Sicherheitsmodell ist – und was nicht

Das Bundle brennt eine Kennzeichnung in die von Contao erzeugten Bildgrößen und führt
darüber ein Protokoll. Daraus ergeben sich zwei Aussagen, die man nicht verwechseln
sollte:

- Die Kennzeichnung ist eine **redaktionelle Aussage des Betreibers**, kein
  fälschungssicheres Wasserzeichen. Sie hält niemanden davon ab, das Bild
  nachzubearbeiten, zuzuschneiden oder das Original direkt zu verlinken. Wer
  Manipulationssicherheit braucht, braucht ein signiertes Herkunftsverfahren (C2PA),
  nicht ein Overlay.
- Die **Erkennung** beim Hinzufügen von Dateien liest nur, was der erzeugende Dienst in
  die Metadaten geschrieben hat. Ein Fund ist ein Indiz, ein fehlender Fund ist **kein**
  Nachweis, dass ein Bild nicht KI-generiert ist – Metadaten überleben Screenshots und
  viele Exporte nicht. Deshalb kennzeichnet das Bundle standardmäßig nichts automatisch.

## Vertrauensgrenzen

- **Backend-Benutzer** gelten als vertrauenswürdig im Rahmen ihrer Contao-Rechte. Wer
  die Kennzeichnung setzen darf, wird über das eigene Recht `netzhirsch_ai_tagp` (Voter
  **und** Contao-Feldrechte) gesteuert; das Lizenz-Modul ist Administratoren
  vorbehalten, weil dort kostenpflichtige Abonnements gestartet werden.
- **Das Kennzeichnungs-Protokoll enthält personenbezogene Daten** (Benutzername,
  Dateipfad, Zeitpunkt). Das Backend-Modul und der CSV-Export prüfen dieselbe
  Modulberechtigung; der Export zusätzlich das Contao-Anfrage-Token und kann auf Wunsch
  ohne Benutzernamen erzeugt werden (`&anonymous=1`). Aufbewahrung standardmäßig 3 Jahre
  (`log_retention_days`).
- **Der Lizenzserver** (`https://license.netzhirsch.de`) ist ein externer Dienst. Das
  Bundle prüft Lizenzen **offline** gegen einen einkompilierten Ed25519-Public-Key; der
  Server kann eine Lizenz ausstellen und widerrufen, aber keinen Code ausführen. Aus
  seinen Antworten wird ausschließlich Folgendes übernommen: ein Token (nur, wenn die
  Signatur passt), das `instance_secret`, der Plan-Name und eine Weiterleitungsadresse,
  die **https** sein und auf einer von **Stripe** gehosteten Domain liegen muss.
- **Zahlungsdaten** werden ausschließlich auf den Seiten von Stripe eingegeben. Karten-
  und SEPA-Daten laufen nie durch Contao und nie durch unseren Server.
- **Token und `instance_secret`** stehen in `var/netzhirsch-ai-tag/license.json`. Sie
  werden nie protokolliert und nie im Backend angezeigt. Die Datei gehört ins Backup und
  nicht ins Repository.

## Was der Code tut, damit es dabei bleibt

- Alle Datenbankzugriffe laufen über vorbereitete Anweisungen; Feldnamen stammen aus
  festen Listen im Code.
- Jede Ausgabe der Backend-Felder wird maskiert; die Twig-Komponente hält sich an die
  Escaping-Regeln des Cores (`sanitize_html`, `insert_tag`) und weicht sie nicht auf.
- Der CSV-Export erzwingt Textzellen: Werte, die mit `=`, `+`, `-`, `@`, Tabulator oder
  Wagenrücklauf beginnen, werden neutralisiert, damit Excel und LibreOffice sie nicht als
  Formel ausführen.
- Die Export-Route liegt unter `/contao/` und damit hinter der Backend-Firewall; sie
  prüft zusätzlich Modulberechtigung und Anfrage-Token.
- Die MCP-Werkzeuge nehmen nur relative Pfade innerhalb des
  Dateiverwaltungs-Verzeichnisses an, prüfen Backend-Parität (`ensureCan()`) und
  verlangen für das Entfernen einer Kennzeichnung eine ausdrückliche Bestätigung.
- Das Protokoll ist über die Oberfläche nicht änderbar (`closed`, `notEditable`,
  `notDeletable` plus eigener Voter); Löschen dürfen nur Administratoren.
- Ein Fehler in der Kennzeichnung bricht nie die Bildauslieferung ab, und aus dem
  Cron-Job fliegt keine Ausnahme.

## Bekannte Grenzen (bewusst, nicht als Lücke gemeldet)

- **Das Lizenz-Gate ist patchbar.** PHP liegt beim Kunden im Klartext. Ziel ist, bequeme
  Weitergabe zu verhindern und absichtliche sichtbar und widerrufbar zu machen – kein
  DRM. Ohne den geheimen Schlüssel des Herstellers gibt es kein gültiges Token.
- **Domainbindung ohne Request.** Geprüft wird der Backend-Host. In Aufrufen ohne
  Request (Konsole, z. B. `contao:resize-images`, Cron) gibt es keinen Host: ist
  `license_backend_url` nicht gesetzt, wird die Domain dem Token selbst entnommen und die
  Bindung ist an dieser Stelle nicht prüfbar. Der Zustand weist das aus
  (`domain_verified`, sichtbar in `netzhirsch:ai-tag:license status`). **Im Betrieb
  `license_backend_url` setzen.**
- **Karenzzeit.** Ein abgelaufenes Token wirkt drei Tage weiter, damit eine Störung
  keinen zahlenden Kunden aussperrt. Ein ausdrücklicher Widerruf des Servers löscht das
  Token dagegen sofort.
- **Direkte Aufrufe der Originaldatei** (`/files/...`, Download-Element, Hotlink) liefern
  das unbearbeitete Original. Gekennzeichnet werden die von Contao erzeugten Bildgrößen;
  das Original wird nie verändert.
- **SVG** wird nicht eingebrannt (Vektorgrafiken laufen nicht durch Imagine); es greift
  nur die Textalternative im Markup.
- Ressourcenverbrauch der Bildbearbeitung (eine zweite Kodierung je Bildgröße) ist
  bekannt und dokumentiert; Bilder entstehen nur für die von Contao definierten
  Bildgrößen.

## Empfehlungen für den Betrieb

- `license_backend_url` setzen und einen echten Systemcron einrichten
  (`vendor/bin/contao-console contao:cron`).
- Zugriff auf *System → Protokoll KI-Kennzeichnung* nur an Benutzer geben, die die
  personenbezogenen Einträge sehen dürfen; für Weitergaben `&anonymous=1` nutzen.
- Backend ausschließlich über HTTPS betreiben.
- `var/netzhirsch-ai-tag/license.json` sichern, aber nicht veröffentlichen und nicht auf
  mehrere Installationen kopieren – die Lizenz ist an eine Installation gebunden.
- Vor dem Ausrollen einer neuen Fassung prüfen, ob eine TrueType-Schrift verfügbar ist:
  ohne Schrift bleibt die Kennzeichnung aus (mit Eintrag im Log).
