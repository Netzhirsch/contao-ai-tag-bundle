# Changelog

Alle nennenswerten Änderungen an diesem Bundle. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung an
[Semantic Versioning](https://semver.org/lang/de/).

## [Unveröffentlicht]

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
- Nachweisprotokoll `tl_netzhirsch_ai_tag_log` mit schreibgeschütztem Backend-Modul in
  der Gruppe *System*.
- Eigenes Recht `netzhirsch_ai_tagp` mit CRUD-Voter für `tl_files` und Schutz des
  Protokolls gegen nachträgliche Änderungen.
- Konfiguration für Schriftdatei, Mindest- und Relativgröße, maximale Label-Fläche und
  Deckkraft.
