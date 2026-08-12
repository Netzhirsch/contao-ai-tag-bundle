# Changelog

Alle nennenswerten Änderungen an diesem Bundle. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung an
[Semantic Versioning](https://semver.org/lang/de/).

## [0.1.0] - 2026-08-12

Erste Fassung. Funktional vollständig und gegen Contao 5.7 (Laufzeit) sowie Contao 5.3
(statische Analyse, aufgelöster Abhängigkeitssatz) geprüft, aber noch nicht im
Kundeneinsatz erprobt: der Backend-Klickweg auf einer echten 5.3-Instanz und der
Imagick-Pfad stehen aus.

### Geändert

- Unterstützt **Contao 5.3 (LTS) bis 5.7+** und **PHP 8.1+** statt nur 5.7/PHP 8.3.
  Sämtliche genutzten APIs (`contao.image.resizer`, `contao.image.metadata`,
  `PageFinder`, `AbstractDataContainerVoter`, `AsCronJob`, `component/_figure.html.twig`
  samt Twig-Hierarchie) existieren in 5.3 unverändert – ohne Kompatibilitätsschicht und
  ohne versionsabhängige Codepfade. Dafür entfielen `readonly class` (PHP 8.2) und
  typisierte Klassenkonstanten (PHP 8.3); Rector, PHPStan (`phpVersion: 80100`),
  `config.platform.php` und die CI-Matrix sind auf den 8.1-Boden gestellt.
- `contao/test-case` entfernt: die Tests nutzen ausschließlich PHPUnit, und die
  5.3-Reihe des Pakets pinnt `contao/core-bundle` auf exakte Patchversionen.

### Hinzugefügt

- Markierung KI-generierter Bilder in der Dateiverwaltung (`tl_files`), mit
  Ordner-Vererbung: der nächstliegende markierte Eintrag gewinnt.
- Eingebrannte Kennzeichnung in jeder von Contao erzeugten Bildgröße über einen
  Decorator auf `contao.image.resizer`; Sprache, Wortlaut und Position werden beim
  Aufbau des `<picture>`-Elements eingefroren und überleben den Deferred-Pfad.
- Sprachsensitiver Wortlaut: Startpunkt der Website (`tl_page`) mit gesetzlicher
  Standardformulierung als Fallback (de/en), pro Datei überschreibbar.
- Automatische Position anhand des wichtigen Bildbereichs sowie automatische
  Kontrastwahl für Schrift und Fläche.
- Lesbarkeitsgrenze: passt das Label nicht lesbar ins Bild, wird es weggelassen und die
  Redaktion beim Markieren darauf hingewiesen.
- Barrierefreie Textalternative über eine Ergänzung des `alt`-Textes in
  `component/_figure.html.twig` sowie die Twig-Funktion `netzhirsch_ai_tag_hint()`.
  Die Komponente überschreibt `figure_component` und wird per `{% use %}` eingebunden –
  `{% extends %}` ist dort nicht möglich (Twig-Trait), und ein `media`-Override würde
  beim direkten Rendern das umgebende `<figure>` verlieren.
- Nachweisprotokoll `tl_netzhirsch_ai_tag_log` mit schreibgeschütztem Backend-Modul in
  der Gruppe *System*, gespiegelt ins Contao-Systemprotokoll (`tl_log`, Aktion *FILES*)
  über den Monolog-Kanal `contao`.
- Aufbewahrungsfrist für das Protokoll (`log_retention_days`, Standard 1095 Tage),
  durchgesetzt von einem täglichen Cron-Job; `0` bewahrt unbegrenzt auf.
- Eigenes Recht `netzhirsch_ai_tagp` mit CRUD-Voter für `tl_files` und Schutz des
  Protokolls gegen nachträgliche Änderungen.
- Konfiguration für Schriftdatei, Mindest- und Relativgröße, maximale Label-Fläche und
  Deckkraft.
- Optionale MCP-Werkzeuge `netzhirsch_ai_tag_get`, `netzhirsch_ai_tag_list` und
  `netzhirsch_ai_tag_set` für `netzhirsch/contao-mcp-bundle`. Registrierung nur, wenn
  das MCP-Bundle installiert ist; Freigabe bleibt beim Betreiber
  (`extension_tools_enabled`). Entfernen einer Kennzeichnung erfordert
  `confirm_destructive=true`, Schreibzugriffe prüfen Backend-Parität per `ensureCan()`
  und werden auf die MCP-Identität attribuiert.
