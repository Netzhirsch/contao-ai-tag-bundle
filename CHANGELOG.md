# Changelog

Alle nennenswerten Änderungen an diesem Bundle. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung an
[Semantic Versioning](https://semver.org/lang/de/).

## [1.0.0] - 2026-08-17

Erste öffentliche Fassung. Gegenüber 0.1.0 sind Erkennung, Lesbarkeits-Ampel,
Nachweis-Export und die Lizenzierung dazugekommen; die **Lizenzprüfung ist in dieser
Fassung aktiv**. Ohne gültige Lizenz wird die Kennzeichnung nicht in die Bilder gebrannt
– die Website läuft unverändert weiter, und Dateiverwaltung wie Vorschaufeld sagen
ausdrücklich, dass gerade nichts eingebrannt wird.

Geprüft gegen Contao 5.7 (Laufzeit) und Contao 5.3 (statische Analyse und aufgelöster
Abhängigkeitssatz), 126 Tests, CI auf PHP 8.1 (lowest und highest) sowie 8.4. Noch nicht
erprobt: der Backend-Klickweg der Lizenzseite gegen einen laufenden Lizenzserver, der
Imagick-Pfad und der Backend-Klickweg auf einer echten 5.3-Instanz.

### Hinzugefügt

- `SECURITY.md`: Meldeweg für Sicherheitslücken, unterstützte Fassungen,
  Sicherheitsmodell, Vertrauensgrenzen und Empfehlungen für den Betrieb.
- Lizenzierung gegen `https://license.netzhirsch.de`: kurzlebige, mit Ed25519 signierte
  Tokens, offline geprüft gegen den einkompilierten Public Key (Signatur, Produkt,
  Domain, Ablauf, High-Water-Mark gegen Uhr-Manipulation), Erneuerung über einen
  stündlichen Cron-Job mit 6-Stunden-Drosselung, drei Tage Karenz und Widerruf als
  Kill-Switch. Lizenzpflichtig ist allein das Einbrennen; Markieren, Protokoll,
  Nachweis-Export und Textalternative bleiben immer verfügbar. Neues Backend-Modul
  *System → Lizenz KI-Kennzeichnung* mit zustandsabhängigen Schaltflächen (Testphase,
  Abonnieren, Abo verwalten, aktualisieren) sowie
  `netzhirsch:ai-tag:license status|renew` für den Betrieb.
- Ohne aktive Lizenz weist das Backend deutlich darauf hin: Fehlermeldung beim Setzen
  der Kennzeichnung, entsprechender Hinweis im Vorschaufeld. Eine stillschweigend
  fehlende Kennzeichnung wäre ein rechtliches Risiko für den Betreiber.

- Erkennung beim Hinzufügen von Dateien: liest IPTC/XMP und erkennt, ob eine Datei
  sich selbst als KI-generiert ausweist (`Iptc4xmpExt:DigitalSourceType`) oder ob
  Metadaten auf einen Generator hindeuten. Standard `suggest` setzt nichts, sondern
  weist die Redaktion darauf hin; `auto` setzt die Kennzeichnung bei einer echten
  Erklärung. Angebunden an `DbafsChangeEvent`, damit jeder Weg in die Dateiverwaltung
  erfasst ist – auf Contao 5.3 über den `postUpload`-Hook plus Nachprüfung beim Öffnen.
- Nachweis-Export als CSV im Protokoll-Modul, wahlweise als vollständiges Protokoll
  oder als Stichtag (`as_of`), auf Wunsch ohne Benutzernamen (`anonymous`). Geprüft
  werden Modulberechtigung und Anfrage-Token; die Ausgabe wird gestreamt, Formeln in
  Zellen werden neutralisiert und Zeiten in der Zeitzone der Installation ausgegeben.
- Lesbarkeits-Ampel in der Dateibearbeitung: zeigt je konfigurierter Bildgröße, ob das
  Label dort lesbar hineinpasst, gerechnet mit Contaos `ResizeCalculator`.

- Konfiguration für das Aussehen der Kennzeichnung: `style` (`box`, `outline`, `plain`),
  `text_color`, `box_color`, `corner_radius` (runde Ecken bis zur Pillenform),
  `padding_ratio`, `margin_ratio`, `uppercase` und `max_font_size`.
- Reichweite steuerbar über `min_width`, `min_height` und `excluded_paths`.
- Die barrierefreie Textfassung ist über `hint_placement` (`alt`, `caption`, `both`,
  `none`) und `hint_separator` platzierbar; dazu die Twig-Funktion
  `netzhirsch_ai_tag_hint_config()`.
- `intermediate_quality` konfigurierbar.

- Gegenüberstellung beider Fassungen in der Dateibearbeitung, sobald die
  Kennzeichnung gesetzt ist – *Ohne Kennzeichnung* neben *Mit Kennzeichnung*.
- `tag_backend_images`, um Backend-Bilder wieder mitzukennzeichnen.

### Behoben

- Das Lizenz-Gate saß im `AiTagResolver` und schaltete damit auch die barrierefreie
  Textalternative im Markup ab – entgegen der zugesagten Aufteilung. Geprüft wird jetzt
  ausschließlich im `AiTagResizer`, also genau dort, wo eingebrannt wird; ein Test hält
  beide Fälle fest.
- Aus den Antworten des Lizenzservers wird nur noch eine Weiterleitung auf eine von
  Stripe gehostete Domain gefolgt (vorher jede `https`-Adresse). Am anderen Ende der
  Weiterleitung sitzt ein angemeldeter Administrator; ein kompromittierter oder
  untergeschobener Serverantwort-Pfad hätte auf eine Phishing-Seite führen können.
- In Aufrufen ohne Request (Konsole, Cron) ist die Domainbindung ohne
  `license_backend_url` nicht prüfbar. Der Lizenzzustand weist das jetzt als
  `domain_verified` aus und `netzhirsch:ai-tag:license status` sagt es ausdrücklich.
- Unmaskierte `&` in den Export-Übersetzungen: die XLIFF-Dateien waren damit kein
  gültiges XML, Symfonys Loader hätte beim ersten Zugriff auf eine Übersetzung des
  Bundles abgebrochen.
- Die CI lief ohne das Secret `COMPOSER_GITHUB_TOKEN` grundsätzlich rot, weil die
  private Dev-Abhängigkeit `netzhirsch/contao-mcp-bundle` nicht installierbar war.
  Ohne Token entfällt sie jetzt samt Repository-Eintrag, `src/Mcp` und `tests/Mcp`
  werden übersprungen (eigene PHPStan- und Rector-Konfiguration, PHPUnit-Gruppe
  `mcp`) und der Lauf meldet als Warnung, was ungeprüft blieb.
- Dev-Abhängigkeit auf `netzhirsch/contao-mcp-bundle: ^1.0` angehoben: die
  0.8-Reihe ist aus dem Repository verschwunden. Geprüft gegen v1.2.0, die
  genutzte Erweiterungs-API ist dort laut EXTENDING.md eingefroren.
- Backend-Bilder wurden mitgekennzeichnet, weil die Dateiverwaltung durch dieselbe
  Bildpipeline läuft – schon das Thumbnail in der Dateiliste trug das Label. Im
  Backend-Scope wird jetzt nicht mehr gekennzeichnet; die erzwungene Fassung der
  Vorschau trifft dieselbe Cache-Datei wie das Frontend, es entsteht kein
  zusätzliches Bild.
- Ohne Obergrenze wurde die Schrift auf großen Bildern absurd groß (90px bei 3000px
  Breite). `max_font_size` deckelt sie, Standard 48.
- Jede Gestaltungseinstellung fließt als Fingerabdruck in den Cache-Schlüssel ein –
  ohne das behielten bereits erzeugte Bilder nach einer Design-Änderung ihr altes
  Aussehen.

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
