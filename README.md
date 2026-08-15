# Contao AI Tag Bundle

**Contao 5.3 (LTS) bis 5.7+, PHP 8.1+.** Alle genutzten Contao-APIs existieren
unverändert in beiden Zweigen – es gibt keine Kompatibilitätsschicht und keine
versionsabhängigen Codepfade.

Kennzeichnet KI-generierte Bilder in Contao so, dass die Kennzeichnung **jede
erzeugte Bildgröße überlebt**: Redaktion markiert eine Datei (oder einen ganzen
Ordner) in der Dateiverwaltung, das Bundle brennt den Hinweis beim Erzeugen jeder
Bildgröße ein – sprachsensitiv, am Bildrand und möglichst außerhalb des wichtigen
Bildbereichs.

> **Kein Rechtsrat.** Das Bundle ist ein Werkzeug zur *Umsetzung* einer Kennzeichnung.
> Die Entscheidung, **welche** Inhalte kennzeichnungspflichtig sind, trifft der
> Betreiber. Art. 50 EU AI Act verlangt die Kennzeichnung insbesondere für Deepfakes
> und ohne redaktionelle Prüfung veröffentlichte Texte; künstlerische, satirische und
> fiktionale Werke sind ausgenommen. Deshalb kennzeichnet das Bundle bewusst **nichts
> automatisch**, sondern nur, was die Redaktion markiert.

## Funktionsumfang

- **Markierung pro Datei und pro Ordner** in der Dateiverwaltung. Bei verschachtelten
  Markierungen gewinnt der nächstliegende markierte Eintrag.
- **Eingebrannte Kennzeichnung in jeder Bildgröße** – die Schriftgröße richtet sich
  nach der ausgelieferten Größe, nicht nach dem Original.
- **Sprachsensitiv**: Der Wortlaut kommt aus dem Startpunkt der jeweiligen Website
  (in Contao je Sprache vorhanden), mit gesetzlicher Standardformulierung als Fallback.
- **Position automatisch** anhand des in Contao gepflegten wichtigen Bildbereichs,
  oder fest wählbar.
- **Kontrast automatisch**: heller Text auf halbtransparent dunkler Fläche oder
  umgekehrt, je nach Untergrund.
- **Barrierefreie Textalternative**: Der Hinweis wird an den `alt`-Text angehängt,
  weil eingebrannte Pixel für Screenreader unsichtbar sind.
- **Nachweisprotokoll** unter *System → Protokoll KI-Kennzeichnung*: wer wann welche
  Datei markiert hat. Nur lesbar, nicht editierbar; zusätzlich erscheint jede Änderung
  im Contao-Systemprotokoll.
- **Eigenes Recht** für das Setzen der Kennzeichnung (Voter + Contao-Feldrechte).

## Installation

```bash
composer require netzhirsch/contao-ai-tag-bundle
vendor/bin/contao-console contao:migrate
```

Zusätzlich muss eine **TrueType-Schrift** verfügbar sein. Ohne Schrift bleiben Bilder
ungekennzeichnet (mit Fehlereintrag im Log) – die Bildauslieferung selbst bricht nie ab.
Gängige System-Schriften werden automatisch gefunden (DejaVu, Liberation, Arial,
Segoe UI). Auf schlanken Containern ohne Schriftpaket den Pfad explizit setzen:

```yaml
# config/config.yaml
netzhirsch_contao_ai_tag:
    font_path: '%kernel.project_dir%/assets/fonts/DejaVuSans.ttf'
```

## Konfiguration

**Schrift und Größe**

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `font_path` | `null` | Absoluter Pfad zur TrueType-Schrift. Ohne Angabe automatische Suche. |
| `min_font_size` | `11` | Kleinste Schriftgröße in Pixel. |
| `relative_font_size` | `0.03` | Wunsch-Schriftgröße relativ zur Bildbreite. |
| `max_font_size` | `48` | Größte Schriftgröße. Ohne Deckel würde das Label auf einem 3000px-Hero 90px groß und damit zum Bildelement. |

**Gestaltung**

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `style` | `box` | `box` = Text auf halbtransparenter Fläche, `outline` = Text mit Kontur, `plain` = nur Text. |
| `text_color` | `null` | Hex-Farbe des Textes. Ohne Angabe automatisch hell oder dunkel, je nach Untergrund. |
| `box_color` | `null` | Hex-Farbe der Fläche bzw. der Kontur. Ohne Angabe die Gegenfarbe zum Text. |
| `box_opacity` | `60` | Deckkraft der Fläche in Prozent. |
| `corner_radius` | `0.25` | Eckenradius relativ zur Label-Höhe. `0` ergibt rechte Winkel, `0.5` eine Pillenform. |
| `padding_ratio` | `0.45` | Innenabstand der Fläche, relativ zur Schriftgröße. |
| `margin_ratio` | `0.5` | Abstand zum Bildrand, relativ zur Schriftgröße. |
| `uppercase` | `false` | Kennzeichnung in Großbuchstaben. |

**Reichweite**

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `max_box_width` | `0.65` | Maximaler Anteil der Bildbreite für das Label. |
| `max_box_height` | `0.3` | Maximaler Anteil der Bildhöhe für das Label. |
| `min_width` / `min_height` | `0` | Bildgrößen darunter werden nicht gekennzeichnet. `0` schaltet die Prüfung ab. |
| `excluded_paths` | `[]` | Pfade, die nie gekennzeichnet werden – auch nicht über die Ordner-Vererbung. |

**Markup und Betrieb**

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `hint_placement` | `alt` | Wohin die barrierefreie Textfassung geht: `alt`, `caption`, `both` oder `none`. |
| `hint_separator` | `' – '` | Trenner zwischen vorhandenem Text und der Kennzeichnung. |
| `intermediate_quality` | `95` | Qualität der ersten Kodierung (die Nachbearbeitung kodiert ein zweites Mal). |
| `log_retention_days` | `1095` | Aufbewahrungsfrist des Protokolls in Tagen (3 Jahre). `0` bewahrt unbegrenzt auf. |

Jede Einstellung, die das Aussehen verändert, fließt über einen Fingerabdruck in den
Cache-Schlüssel der Bildgröße ein. Eine Design-Änderung erzeugt die betroffenen Bilder
also automatisch neu; der Bild-Cache muss nicht geleert werden.

Zu den runden Ecken: die Fläche entsteht aus drei Rechtecken und vier Kreissegmenten,
die sich bewusst **nicht** überlappen – bei halbtransparenter Fläche würde die Farbe an
den Nahtstellen sonst zweimal aufgetragen und als dunklere Linie sichtbar. GD zeichnet
Bögen ohne Kantenglättung, die Rundung ist deshalb bei kleinen Radien leicht stufig;
Imagick glättet.

## Protokoll und Aufbewahrung

Jede Änderung landet an zwei Stellen:

- **`tl_netzhirsch_ai_tag_log`** (*System → Protokoll KI-Kennzeichnung*) als belastbarer
  Nachweis, mit filterbaren Spalten (Aktion, Datei/Ordner, Datei­pfad, Benutzer).
- **Contao-Systemprotokoll** (`tl_log`, Aktion *FILES*) als Hinweis am gewohnten Ort.

Warum eine eigene Tabelle, obwohl es das Systemprotokoll gibt: Contao räumt `tl_log`
über `PurgeExpiredDataCron` nach der Systemeinstellung `logPeriod` ab – **Standard 7
Tage**. Ein Nachweis, der sich nach einer Woche selbst löscht, ist keiner. Die eigene
Tabelle hat stattdessen eine eigene Frist (`log_retention_days`, Standard 3 Jahre), die
ein täglicher Cron-Job durchsetzt: lang genug für den Nachweis, ohne Benutzernamen
dauerhaft vorzuhalten.

Anlegen und Ändern sind über die Oberfläche für alle gesperrt, Löschen nur für
Administratoren.

## Wortlaut

Reihenfolge, die greift:

1. **Text an der Datei** (Feld *Text der Kennzeichnung* in der Dateiverwaltung)
2. **Text am Startpunkt der Website** (`tl_page`, Palette *Startpunkt einer Website*)
3. **Standardformulierung** aus den Bundle-Übersetzungen – `KI-generiert` (de) bzw.
   `AI-generated` (en)

Der Standard ist absichtlich kurz: er muss auch in kleinen Bildgrößen lesbar bleiben.
Weitere Sprachen ergänzen, indem eine Datei `translations/netzhirsch_ai_tag.<locale>.xlf`
im Projekt hinterlegt wird.

Ein geänderter Wortlaut wirkt sofort: der Text geht in den Cache-Schlüssel der
Bildgröße ein, bereits erzeugte Bilder werden dadurch automatisch neu erzeugt. Ein
manuelles Leeren des Bild-Caches ist nicht nötig.

## Zu kleine Bildgrößen

Passt das Label nicht lesbar ins Bild (Grenzwerte siehe Konfiguration), wird **nichts
eingebrannt**, statt eine unlesbare Kennzeichnung zu erzeugen. Beim Markieren weist das
Backend darauf hin, wenn das Bild selbst dafür zu klein ist. In diesen Fällen trägt nur
die Textalternative im Markup die Information – eine sichtbare Kennzeichnung muss dann
im Layout gelöst werden (z. B. größere Bildgröße oder Bildunterschrift).

## MCP-Werkzeuge (optional)

Ist `netzhirsch/contao-mcp-bundle` installiert, bringt das Bundle drei Werkzeuge für
den MCP-Server mit. Ohne das MCP-Bundle wird der Service gar nicht registriert – es
besteht keine harte Abhängigkeit.

| Werkzeug | Zweck |
|---|---|
| `netzhirsch_ai_tag_get` | Liest den Kennzeichnungsstand einer Datei oder eines Ordners, inklusive Vererbung (`inherited_from`), wirksamem Text und ob das Format überhaupt einbrennbar ist. Nur lesend. |
| `netzhirsch_ai_tag_list` | Listet die als KI-generiert markierten Dateien und Ordner, optional auf einen Ordner begrenzt. Nur lesend. |
| `netzhirsch_ai_tag_set` | Setzt oder entfernt die Kennzeichnung, optional mit Position und Wortlaut. |

Wichtig für den Betrieb:

- **Standardmäßig aus.** Wie jedes Extension-Tool sind sie erst erreichbar, wenn der
  Betreiber sie freischaltet – im Backend unter *MCP-Server → Tools* oder über
  `extension_tools_enabled` in `var/mcp/config.json`.
- **Entfernen ist geschützt.** `netzhirsch_ai_tag_set` mit `ai_generated=false`
  verlangt `confirm_destructive=true` – eine Kennzeichnung zu entfernen kann eine
  Rechtspflicht verletzen, das darf kein halluzinierter Aufruf nebenbei tun.
- **Backend-Parität.** Die Werkzeuge sind für Benutzer mit Zugriff auf die
  Dateiverwaltung freigegeben (wie die `file_*`-Werkzeuge des Cores); der schreibende
  Aufruf prüft zusätzlich per `ensureCan()` gegen Contaos Voter, sodass das Recht
  *KI-Kennzeichnung setzen* und die Feldrechte genauso greifen wie im Backend.
- **Nachweis.** Jede Änderung landet im Kennzeichnungs-Protokoll und im
  Systemprotokoll, attribuiert auf die aufrufende MCP-Identität statt auf `system`.

Für die Entwicklung wird das MCP-Bundle als `require-dev` aus dem privaten
GitHub-Repository geladen; die CI braucht dafür Zugangsdaten (`COMPOSER_AUTH`).

## Grenzen (bewusst)

- **SVG wird nicht eingebrannt.** Vektorgrafiken laufen in Contao nicht durch Imagine.
  Beim Markieren erscheint ein entsprechender Hinweis; die Textalternative greift
  trotzdem.
- **Direkte Aufrufe der Originaldatei** (`/files/...`, Download-Element, Hotlink)
  liefern das unbearbeitete Original. Gekennzeichnet werden die von Contao erzeugten
  Bildgrößen. Das Original wird nie verändert.
- **Deprecated-Templates**: Abbildungen, die über
  `@ContaoCore/Image/Studio/figure.html.twig` gerendert werden (in Contao 5 als
  veraltet markiert, entfällt in Contao 6), erhalten die eingebrannte Kennzeichnung,
  aber keine Textalternative. Die Standard-Inhaltselemente nutzen
  `@Contao/component/_figure.html.twig` und sind vollständig abgedeckt.
- **Schreibzugriffe ohne Backend-Benutzer** (Console, DC-API, MCP-Werkzeuge) können die
  Kennzeichnungsfelder nicht ändern – der Voter verlangt ein Recht, das nur Benutzer
  haben. Automatisierte Massenmarkierung ist damit bewusst nicht über den
  DataContainer möglich.
- **Ein Zustand pro Eintrag**: Es gibt keine „ausdrücklich nicht KI"-Markierung, die
  eine Ordner-Markierung für eine einzelne Datei aufhebt.

## Technischer Kern

Die Kennzeichnung entsteht in einem **Decorator auf `contao.image.resizer`**
(`AiTagResizer`), nicht in einer Subklasse:

- `resize()` friert Sprache, Wortlaut und Ecke in einer eigenen Imagine-Option ein.
  Notwendig, weil Contao Bilder überwiegend *deferred* erzeugt – im späteren Request
  auf `/assets/images/...` gibt es weder Seitensprache noch wichtigen Bildbereich.
  Weil Contao die Imagine-Optionen in den Cache-Pfad hasht, entsteht dadurch pro
  Sprache und Textfassung automatisch eine eigene Datei.
- `resizeDeferredImage()` liest die eingefrorene Option und brennt die Kennzeichnung
  auf das fertige Bild.

Eine Subklasse wäre der falsche Weg: `DeferredResizer::executeResize()` trifft dort die
Deferral-Entscheidung (ein Override deaktiviert Deferred-Rendering global), und
`executeDeferredResize()` ruft per `parent::` direkt die Basisklasse auf und überspringt
jede Subklasse – der Deferred-Pfad bliebe ungekennzeichnet.

Kosten: eine zweite Kodierung des fertigen Bildes, gemessen etwa **+8 % Dateigröße** bei
Fotos. Die erste Kodierung läuft dafür mit angehobener Qualität, gespeichert wird mit
der Zielqualität der Bildgröße. Über Contao-Bildgrößen konfigurierte
Copyright-Metadaten werden nach dem Zeichnen wieder angewandt.

## Rechte

Das Recht *KI-Kennzeichnung setzen und entfernen* (`netzhirsch_ai_tagp`) steuert, wer die
Felder ändern darf – in `tl_user_group` und `tl_user`. Administratoren dürfen immer.
Zusätzlich sind die Felder `exclude`, unterliegen also auch den Contao-Feldrechten.

## Qualität und Versionsabdeckung

```bash
composer all   # ECS, PHPStan (Level 6), Rector, PHPUnit
```

Die Entwicklungsumgebung ist über `config.platform.php = 8.1.0` bewusst auf den
**untersten** unterstützten Stand festgenagelt: lokal wird damit dieselbe
Abhängigkeitsauflösung getestet, die eine Contao-5.3-Installation bekommt (DBAL 3,
Monolog 2, Symfony 6.4). PHPStan läuft zusätzlich mit `phpVersion: 80100`, damit die
Verwendung neuerer Sprachfeatures hier auffällt und nicht erst auf der Zielinstallation.
Deshalb kein `readonly class` (8.2) und keine typisierten Klassenkonstanten (8.3) im
Code – Rector ist entsprechend auf das PHP-8.1-Set gestellt.

Die CI prüft PHP 8.1 (`lowest` und `highest`) sowie PHP 8.4.

## Lizenz

Proprietär, © Netzhirsch GmbH.
