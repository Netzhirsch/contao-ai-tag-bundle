# Contao AI Tag Bundle

Kennzeichnet KI-generierte Bilder in Contao 5.7+ so, dass die Kennzeichnung **jede
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
  Datei markiert hat. Nur lesbar, nicht editierbar.
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

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `font_path` | `null` | Absoluter Pfad zur TrueType-Schrift. Ohne Angabe automatische Suche. |
| `min_font_size` | `11` | Kleinste Schriftgröße in Pixel. |
| `relative_font_size` | `0.03` | Wunsch-Schriftgröße relativ zur Bildbreite. |
| `max_box_width` | `0.65` | Maximaler Anteil der Bildbreite für das Label. |
| `max_box_height` | `0.3` | Maximaler Anteil der Bildhöhe für das Label. |
| `box_opacity` | `60` | Deckkraft der Label-Fläche in Prozent. |

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

## Qualität

```bash
composer all   # ECS, PHPStan (Level 6), Rector, PHPUnit
```

## Lizenz

Proprietär, © Netzhirsch GmbH.
