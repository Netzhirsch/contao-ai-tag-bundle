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
- **Lizenz** unter *System → Lizenz KI-Kennzeichnung*: Testphase, Abonnement und
  Zustand. Lizenzpflichtig ist nur das Einbrennen, siehe *Lizenz und Aktivierung*.

## Installation

```bash
composer require netzhirsch/contao-ai-tag-bundle
vendor/bin/contao-console contao:migrate
```

Danach unter *System → Lizenz KI-Kennzeichnung* die Testphase starten oder das
Abonnement abschließen – ohne aktive Lizenz wird die Kennzeichnung nicht eingebrannt
(siehe *Lizenz und Aktivierung*). Voraussetzung ist `ext-sodium` (in üblichen
PHP-Builds vorhanden) und ein laufender Cron.

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
| `detection` | `suggest` | Erkennung beim Hinzufügen von Dateien: `suggest`, `auto` oder `off`. Siehe *Erkennung*. |
| `tag_backend_images` | `false` | Auch Bilder im Backend kennzeichnen. Standardmäßig aus, siehe *Backend-Vorschau*. |
| `hint_placement` | `alt` | Wohin die barrierefreie Textfassung geht: `alt`, `caption`, `both` oder `none`. |
| `hint_separator` | `' – '` | Trenner zwischen vorhandenem Text und der Kennzeichnung. |
| `intermediate_quality` | `95` | Qualität der ersten Kodierung (die Nachbearbeitung kodiert ein zweites Mal). |
| `log_retention_days` | `1095` | Aufbewahrungsfrist des Protokolls in Tagen (3 Jahre). `0` bewahrt unbegrenzt auf. |
| `license_backend_url` | `''` | Adresse oder Hostname des Backends, auf den die Lizenz ausgestellt ist. Nur für Cron und CLI nötig, siehe *Lizenz*. |
| `license_server_url` | `''` | Abweichender Lizenzserver. Nur für die Entwicklung. |

Jede Einstellung, die das Aussehen verändert, fließt über einen Fingerabdruck in den
Cache-Schlüssel der Bildgröße ein. Eine Design-Änderung erzeugt die betroffenen Bilder
also automatisch neu; der Bild-Cache muss nicht geleert werden.

Zu den runden Ecken: die Fläche entsteht aus drei Rechtecken und vier Kreissegmenten,
die sich bewusst **nicht** überlappen – bei halbtransparenter Fläche würde die Farbe an
den Nahtstellen sonst zweimal aufgetragen und als dunklere Linie sichtbar. GD zeichnet
Bögen ohne Kantenglättung, die Rundung ist deshalb bei kleinen Radien leicht stufig;
Imagick glättet.

## Erkennung

Viele Generatoren schreiben ihre Herkunft in die Metadaten. Das Bundle liest sie, wenn
eine Datei neu in `tl_files` landet, und unterscheidet zwei Stufen:

| Signal | Aussage |
|---|---|
| XMP `Iptc4xmpExt:DigitalSourceType` = `trainedAlgorithmicMedia` (oder `compositeWith…`) | **Erklärung** – der IPTC-Standard, den C2PA und die großen Generatoren schreiben |
| `xmp:CreatorTool`, `photoshop:Credit`, `dc:creator`, EXIF `Software` mit bekanntem Generatornamen | Indiz |
| PNG-Textblock `parameters` (Stable Diffusion legt dort den Prompt ab) | Indiz |

**Gesetzt wird nichts automatisch** (`detection: suggest`). Die Datei bekommt eine Notiz,
die beim Bearbeiten erscheint; die Entscheidung bleibt bei der Redaktion, weil sie vom
Inhalt abhängt – ein Deepfake ist kennzeichnungspflichtig, eine Illustration nicht. Mit
`detection: auto` setzt das Bundle die Kennzeichnung bei einer echten Erklärung selbst
und protokolliert das mit der Quelle; `off` schaltet die Prüfung ab.

Angebunden ist die Erkennung an `DbafsChangeEvent` – damit erreicht sie **jeden** Weg in
die Dateiverwaltung: Upload, Drag and Drop, `contao:filesync`, MCP, per FTP nachgeschobene
Dateien.

> **Auf Contao 5.3** gibt es dieses Ereignis noch nicht. Dort greift der
> `postUpload`-Hook, der nur den Upload im Backend-Dateimanager abdeckt; alle anderen
> Dateien werden geprüft, sobald sie jemand im Backend öffnet. Das Bundle registriert
> automatisch den jeweils passenden Weg.

**Grenzen:** Metadaten überleben Screenshots, Messenger und viele Exporte nicht. Die
Erkennung ist ein Netz gegen das Vergessen, kein Beweis für das Gegenteil – und
ausdrücklich keine Bildanalyse, sondern nur die Auswertung dessen, was die Datei über
sich selbst behauptet.

## Lesbarkeit je Bildgröße

Ist die Kennzeichnung gesetzt, zeigt die Dateibearbeitung für jede in dieser
Installation angelegte Bildgröße, ob das Label dort noch lesbar hineinpasst. Gerechnet
wird mit Contaos eigenem `ResizeCalculator`, die Maße entsprechen also der späteren
Auslieferung. Wo es nicht passt, trägt allein die Textalternative im Markup – und genau
das steht dann in der Übersicht statt einer stillen Auslassung.

## Backend-Vorschau

Die Dateiverwaltung läuft durch dieselbe Bildpipeline wie das Frontend – ohne
Gegenmaßnahme trägt deshalb schon das Thumbnail in der Dateiliste die Kennzeichnung.
Dort soll aber die Datei zu sehen sein, nicht ihre Auslieferung. **Bilder im
Backend-Scope werden daher nicht gekennzeichnet** (umstellbar über
`tag_backend_images`).

Stattdessen zeigt die Dateibearbeitung beide Fassungen nebeneinander, sobald die
Kennzeichnung gesetzt ist: *Ohne Kennzeichnung* und *Mit Kennzeichnung*. Die rechte
Fassung erzwingt die Kennzeichnung gezielt und trifft dabei **dieselbe Cache-Datei wie
das Frontend** – es entsteht kein zusätzliches Bild.

Die Vorschau zeigt das Label in der Größe der Vorschau. In der ausgelieferten
Bildgröße fällt es entsprechend größer oder kleiner aus, unterhalb der
Lesbarkeitsgrenze bleibt es ganz weg.

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

### Nachweis-Export

Im Protokoll-Modul liegt eine Schaltfläche **CSV-Export**. Zwei Fassungen:

- ohne Parameter das vollständige Protokoll,
- mit `&as_of=JJJJ-MM-TT` der **Stichtag**: welche Dateien an diesem Tag gekennzeichnet
  waren, rekonstruiert aus dem Protokoll (je Pfad zählt der letzte Eintrag bis dahin).
- `&anonymous=1` lässt die Benutzernamen weg – für Vorlagen an Dritte.

Zur Sicherheit: der Export prüft dieselbe Modulberechtigung wie die Ansicht, verlangt
das Contao-Anfrage-Token (ein untergeschobener Link läuft ins Leere), streamt zeilenweise
statt alles in den Speicher zu laden, und **neutralisiert Formeln** – ein Dateiname wie
`=cmd|…` würde von Excel sonst beim Öffnen ausgeführt. Zeiten stehen in der Zeitzone der
Installation, passend zur Backend-Ansicht.

> Der Stichtag kann nur zeigen, was das Protokoll enthält: Kennzeichnungen aus der Zeit
> vor der Installation des Bundles, direkte Datenbankänderungen und Einträge jenseits der
> Aufbewahrungsfrist (`log_retention_days`) sind darin nicht enthalten.

Ein Eingabeformular für den Stichtag gibt es noch nicht – das Datum wird an die URL
angehängt.

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

## Lizenz und Aktivierung

Lizenzpflichtig ist **allein das Einbrennen** der Kennzeichnung in die erzeugten
Bildgrößen. Markieren in der Dateiverwaltung, Erkennung, Protokoll, Nachweis-Export und
die Textalternative im Markup funktionieren immer – der Zugriff auf die eigenen
Nachweise darf nicht an einem Abonnement hängen.

Verwaltet wird das unter *System → Lizenz KI-Kennzeichnung* (nur für
Administratoren): Zustand, *Testphase starten*, *Abonnieren*, *Abo verwalten* und
*Lizenz aktualisieren*.

**Wie es funktioniert.** Der Lizenzserver (`https://license.netzhirsch.de`) stellt
kurzlebige, mit Ed25519 **signierte** Tokens aus; das Bundle prüft sie **offline** gegen
den einkompilierten Public Key und erneuert sie über einen stündlichen Cron-Job. Ein
Serverausfall ist damit unkritisch, ein Widerruf greift trotzdem innerhalb weniger
Stunden. Geprüft werden Signatur, Produkt, Domain und Ablauf; gegen ein Zurückstellen
der Systemuhr steht eine High-Water-Mark.

- **Domainbindung.** Das Token gilt für den Backend-Host, normalisiert (klein
  geschrieben, ohne Port, ohne führendes `www.`). Kopieren auf eine andere Domain nützt
  nichts. Kostenlos ohne Lizenz sind serverseitig nur `localhost`, `127.0.0.1`, `::1`
  und `*.localhost` – **`.test`, `.local` und `.ddev.site` nicht.**
- **Instanzbindung.** `var/netzhirsch-ai-tag/license.json` enthält Token und
  `instance_secret`. Beim Umzug auf einen anderen Server die Datei mitnehmen und
  *Lizenz aktualisieren* klicken, sonst antwortet der Server `instance_mismatch`. Die
  Datei gehört ins Backup, nicht ins Repository.
- **Cron.** Die Erneuerung läuft als `hourly`-Cron-Job, echte Serveraufrufe sind auf
  einen alle sechs Stunden gedrosselt. Auf Seiten mit wenig Verkehr braucht es dafür
  einen echten Systemcron (`vendor/bin/contao-console contao:cron`).
- **Karenz.** Ein abgelaufenes Token wirkt noch drei Tage weiter, damit eine kurze
  Netz- oder Serverstörung niemanden aussperrt. Nur ein ausdrücklicher Widerruf des
  Servers löscht das Token sofort.
- **Kein Request, kein Host.** In Cron und CLI gibt es keinen Request-Host. Die Domain
  kommt dann aus dem gespeicherten Token – gegen sich selbst geprüft passt jede Angabe,
  die Bindung ist dort also nicht prüfbar. `netzhirsch:ai-tag:license status` weist das
  aus; **im Betrieb `license_backend_url` setzen**, dann wird auch in der Konsole gegen
  den konfigurierten Host geprüft.

Zustand prüfen oder Token sofort erneuern, ohne Backend:

```bash
vendor/bin/contao-console netzhirsch:ai-tag:license status
vendor/bin/contao-console netzhirsch:ai-tag:license renew
```

**Ohne aktive Lizenz** bleibt die Website unangetastet und markierte Bilder werden
unverändert ausgeliefert. Das sagt das Bundle deutlich: beim Setzen der Kennzeichnung
erscheint eine Fehlermeldung, und das Vorschaufeld zeigt statt der Gegenüberstellung
denselben Hinweis. Eine stillschweigend fehlende Kennzeichnung wäre ein rechtliches
Risiko für den Betreiber.

**Sicherheit.** Der Public Key steht im Code und nicht in der Konfiguration – ein
konfigurierbarer Schlüssel ließe sich gegen einen selbst erzeugten tauschen. Die
Server-Adresse ist ebenfalls einkompiliert. Bezahlt wird ausschließlich auf den von
Stripe gehosteten Seiten; **Karten- und SEPA-Daten laufen nie durch Contao**. Gefolgt
wird nur eine `https://`-Adresse auf einer von Stripe gehosteten Domain – am anderen Ende
der Weiterleitung sitzt ein angemeldeter Administrator. Token und `instance_secret`
erscheinen weder im Protokoll noch im Backend. Alles Weitere in [SECURITY.md](SECURITY.md).

Ehrliche Grenze: PHP liegt beim Kunden im Klartext, das Gate ist patchbar. Ziel ist,
bequeme Weitergabe zu verhindern und absichtliche sichtbar und widerrufbar zu machen –
kein DRM.

## Grenzen (bewusst)

- **Ohne einkompilierten Public Key wird nicht geprüft.** Solange
  `LicenseToken::VENDOR_PUBLIC_KEY_B64` leer ist, gilt die Fassung als nicht
  lizenzpflichtig und brennt immer ein. Das ist die Reihenfolge beim Ausrollen: erst
  Produkt und Pläne auf dem Server anlegen, dann interne Lizenzen ausstellen, danach den
  Schlüssel einsetzen. Ein Update mit Schlüssel vor den internen Lizenzen sperrt die
  eigenen Installationen aus.
- **Rückkehr von Stripe.** Der Lizenzserver hängt für alle Produkte denselben
  Parameter `mcp_billing` an die Rückkehr-Adresse. Der Listener greift deshalb nur auf
  dem nackten `/contao`-Aufruf; ist auf derselben Installation ein weiteres
  Netzhirsch-Bundle mit Lizenzierung aktiv, landet die Rückkehr eventuell auf dessen
  Lizenzseite. Die Lizenz aktiviert sich trotzdem – spätestens mit dem stündlichen Cron
  oder auf Klick über *Lizenz aktualisieren*.
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

## Sicherheit

Sicherheitslücken bitte nicht über öffentliche Issues melden – der Weg steht in
[SECURITY.md](SECURITY.md). Dort stehen auch das Sicherheitsmodell, die
Vertrauensgrenzen (u. a. personenbezogene Daten im Protokoll) und die Empfehlungen für
den Betrieb.

## Lizenz

Proprietär, © Netzhirsch GmbH. Siehe [LICENSE](LICENSE).
