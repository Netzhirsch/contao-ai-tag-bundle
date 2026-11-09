<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Contao\Image\Metadata\MetadataReaderWriter;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\FontLocator;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class TagRendererTest extends TestCase
{
    private string|null $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    /**
     * Die Flaeche entsteht aus drei Rechtecken und vier Kreissegmenten. Ueberlappen
     * die sich auch nur um eine Pixelreihe, wird die halbtransparente Farbe dort
     * zweimal aufgetragen und die Naht ist als dunklere Linie sichtbar.
     *
     * Der Test macht den Text unsichtbar (weiss auf weiss), sodass im Bild nur zwei
     * Helligkeiten vorkommen duerfen: Hintergrund und einfach aufgetragene Flaeche.
     */
    public function testRoundedBoxIsDrawnWithoutOverlappingSeams(): void
    {
        $renderer = $this->renderer(new TagStyle(
            textColor: '#ffffff',
            boxColor: '#000000',
            boxOpacity: 50,
            cornerRadius: 0.4,
        ));

        $image = $this->render($renderer);
        $levels = $this->luminanceLevels($image);

        $this->assertNotEmpty(
            array_filter($levels, static fn (int $level): bool => $level > 100 && $level < 200),
            'Die Flaeche wurde gar nicht gezeichnet.',
        );

        $this->assertSame(
            [],
            array_values(array_filter($levels, static fn (int $level): bool => $level <= 100)),
            'Zu dunkle Pixel bedeuten doppelt aufgetragene Transparenz an den Nahtstellen der Ecken.',
        );
    }

    /**
     * Die Schriftgroesse der Konfiguration ist in PIXELN. Imagine rechnet in Punkten
     * bei 96 dpi, und ohne Umrechnung wurde eine Schrift von 40 "Pixeln" als 40 pt =
     * 53 px gezeichnet - das Label war ein Drittel zu gross und deckte auf einem
     * 1296-px-Bild gut ein Viertel der Breite ab.
     *
     * "KI-generiert" ist in DejaVu Sans 5,918 em breit, also bei 40 px rund 237 px.
     * Die Box ist so breit wie der Text plus beidseitig der Innenabstand (0,45 em).
     */
    public function testTheFontSizeIsInPixelsNotInPoints(): void
    {
        $renderer = $this->renderer(new TagStyle(
            textColor: '#ffffff',
            boxColor: '#000000',
            boxOpacity: 100,
            cornerRadius: 0.0,
            minFontSize: 40,
            maxFontSize: 40,
        ));

        $image = $this->render($renderer, 800, 300);
        [$width, $height] = $this->darkBoxSize($image);

        $expectedWidth = 5.918 * 40 + 2 * 0.45 * 40;

        $this->assertEqualsWithDelta($expectedWidth, $width, $expectedWidth * 0.08, \sprintf('Box %d px breit, erwartet etwa %d px (mit Punkten gerechnet waeren es etwa 343).', $width, $expectedWidth));
        // Zeilenhoehe von DejaVu Sans ist rund 1,16 em; dazu der Innenabstand.
        $this->assertLessThan(40 * 1.16 + 2 * 0.45 * 40 + 6, $height);
    }

    /**
     * Ein schmales Banner (1920 x 240 px) bekam gar kein Label: mit der Wunschgroesse
     * waere es 91 px hoch und damit ueber der Grenze von 30 % der Bildhoehe, und
     * statt zu verkleinern entfiel es. Erfahren hat es niemand - ein Bild ohne
     * Kennzeichnung ist das groessere Risiko als ein kleineres Label.
     */
    public function testAWideBannerGetsASmallerLabelInsteadOfNone(): void
    {
        $renderer = $this->renderer($this->solidStyle());

        $file = $this->render($renderer, 1920, 240);
        [$width, $height] = $this->darkBoxSize($file);

        $this->assertLessThanOrEqual(240 * 0.3 + 1, $height, 'Das Label muss unter der Grenze von 30 % der Bildhoehe bleiben.');
        $this->assertLessThan(324, $width, 'Es muss kleiner sein als das Label in Wunschgroesse (324 px).');
        $this->assertTrue($renderer->isLegible(1920, 240, 'KI-generiert'), 'Die Backend-Pruefung muss dasselbe entscheiden wie das Zeichnen.');
    }

    /**
     * Laengerer Wortlaut (etwa ein eigener Text je Datei) wird ebenfalls verkleinert,
     * bis er hineinpasst - und bleibt unter 65 % der Bildbreite.
     */
    public function testALongWordingIsShrunkToFit(): void
    {
        $renderer = $this->renderer($this->solidStyle());
        $text = 'Mit KI erzeugt und nachträglich bearbeitet';

        $file = $this->render($renderer, 1296, 707, $text);
        [$width] = $this->darkBoxSize($file);

        $this->assertLessThanOrEqual(1296 * 0.65 + 1, $width);
        $this->assertTrue($renderer->isLegible(1296, 707, $text));
    }

    /**
     * Die Untergrenze bleibt: passt das Label auch in der kleinsten Schriftgroesse
     * nicht, wird weiterhin nichts gezeichnet - und die Backend-Pruefung sagt
     * dasselbe. Eine winzige Kennzeichnung, die niemand lesen kann, waere schlimmer
     * als keine.
     */
    public function testNothingIsDrawnWhenEvenTheSmallestSizeDoesNotFit(): void
    {
        $renderer = $this->renderer($this->solidStyle());

        $this->assertFalse($this->renderInto($renderer, 100, 40));
        $this->assertFalse($renderer->isLegible(100, 40, 'KI-generiert'));
    }

    public function testSquareBoxRemainsAvailable(): void
    {
        $renderer = $this->renderer(new TagStyle(
            textColor: '#ffffff',
            boxColor: '#000000',
            boxOpacity: 50,
            cornerRadius: 0.0,
        ));

        $levels = $this->luminanceLevels($this->render($renderer));

        $this->assertSame([], array_values(array_filter($levels, static fn (int $level): bool => $level <= 100)));
    }

    /**
     * Ohne Schrift wird nichts gezeichnet - aber die Datei wird trotzdem auf die
     * Zielqualitaet gebracht. Frueher stieg apply() hier vorzeitig aus, und das Bild
     * blieb bei der angehobenen ersten Kodierung liegen: ungekennzeichnet und
     * deutlich groesser.
     *
     * Bewusst ohne die Schrift-Ueberspringen der anderen Tests: dieser Fall ist genau
     * der, der auf einem System mit Schrift nie auftritt - und dort ist er schon
     * einmal unbemerkt geblieben.
     */
    public function testWithoutAFontTheFileIsStillBroughtToTheTargetQuality(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('Der Test braucht die GD-Erweiterung.');
        }

        $renderer = new TagRenderer(
            new Imagine(),
            new MetadataReaderWriter(),
            new FontLocator(null, '/gibt/es/nicht.ttf'),
            new Filesystem(),
            new TagStyle(),
        );

        $this->assertFalse($renderer->canDraw());
        $this->assertSame('', $renderer->fontPath());

        $this->file = tempnam(sys_get_temp_dir(), 'aitag').'.jpg';
        $this->noisyImage(600, 400)->save($this->file, ['jpeg_quality' => 100]);
        $before = filesize($this->file);

        $drawn = $renderer->apply(
            $this->file,
            new AiTagOptions('de', AiTagOptions::POSITION_TOP_LEFT, 'KI-generiert', 40, 'test'),
            [],
        );

        clearstatcache(true, $this->file);

        $this->assertFalse($drawn, 'Ohne Schrift kann nichts eingebrannt sein.');
        $this->assertLessThan($before * 0.8, filesize($this->file), 'Die Datei muss auf die Zielqualitaet neu kodiert sein.');
    }

    public function testNothingIsDrawnBelowTheConfiguredMinimumSize(): void
    {
        $renderer = $this->renderer(new TagStyle(minWidth: 800));

        $this->assertFalse($this->renderInto($renderer, 400, 200));
    }

    /**
     * Wo der volle Wortlaut auch in der kleinsten Schriftgroesse nicht passt, steht
     * die Kurzform ("KI") im Bild, statt dass das Label ganz entfaellt: ein kurzes
     * Label ist besser als gar keines, und "AI" ist die einzige Abkuerzung, die die
     * EU-Kommission vorsieht. 100 x 80 px ist so ein Fall - der volle Text braeuchte
     * mindestens 77 px bei hoechstens 65 px erlaubter Breite, die Kurzform braucht
     * rund 30 px.
     */
    public function testTheShortFormIsDrawnWhereTheFullWordingDoesNotFit(): void
    {
        $renderer = $this->renderer($this->solidStyle());

        $this->assertFalse($this->renderInto($renderer, 100, 80), 'Ohne Kurzform entfaellt das Label.');
        $this->assertTrue($this->renderInto($renderer, 100, 80, 'KI-generiert', 'KI'));

        [$width, $height] = $this->darkBoxSize((string) $this->file);

        $this->assertLessThanOrEqual(100 * 0.65 + 1, $width);
        $this->assertLessThanOrEqual(80 * 0.3 + 1, $height);
        $this->assertLessThan(40, $width, 'Es ist die Kurzform und nicht der verkleinerte volle Text.');
    }

    /**
     * Die Kurzform kommt erst, wenn der volle Wortlaut nicht passt - sonst wuerde ein
     * Bild, auf dem der volle Text passt, unnoetig verkuerzt.
     */
    public function testTheFullWordingWinsWhereItFits(): void
    {
        $renderer = $this->renderer($this->solidStyle());

        $this->assertTrue($this->renderInto($renderer, 800, 450, 'KI-generiert', 'KI'));
        [$width] = $this->darkBoxSize((string) $this->file);

        $this->assertGreaterThan(100, $width, 'Auf 800 px Breite passt der volle Wortlaut, er darf nicht verkuerzt werden.');
    }

    /**
     * Die Ampel im Backend und das Zeichnen entscheiden nach derselben Regel; fit()
     * sagt, was in einer Groesse eingebrannt wuerde.
     */
    public function testFitReportsFullShortOrNone(): void
    {
        $renderer = $this->renderer($this->solidStyle());

        $this->assertSame(TagRenderer::FIT_FULL, $renderer->fit(800, 450, 'KI-generiert', 'KI'));
        $this->assertSame(TagRenderer::FIT_SHORT, $renderer->fit(100, 80, 'KI-generiert', 'KI'));
        $this->assertSame(TagRenderer::FIT_NONE, $renderer->fit(100, 80, 'KI-generiert', ''), 'Ohne Kurzform bleibt nichts.');
        $this->assertSame(TagRenderer::FIT_NONE, $renderer->fit(20, 10, 'KI-generiert', 'KI'), 'Auch die Kurzform hat eine Untergrenze.');

        $this->assertTrue($renderer->isLegible(100, 80, 'KI-generiert', 'KI'));
        $this->assertFalse($renderer->isLegible(100, 80, 'KI-generiert'));
    }

    private function solidStyle(): TagStyle
    {
        return new TagStyle(
            textColor: '#ffffff',
            boxColor: '#000000',
            boxOpacity: 100,
            cornerRadius: 0.0,
        );
    }

    private function renderer(TagStyle $style): TagRenderer
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('Die Zeichenpruefung braucht die GD-Erweiterung.');
        }

        // Nicht ueberspringen: das Bundle liefert seine Schrift mit. Ein frueherer Fehler
        // (ohne Schrift ungekennzeichnet) blieb unbemerkt, weil sich genau diese Tests dann
        // selbst uebersprungen haben - fehlt sie jetzt, soll der Test rot werden.
        $this->assertNotNull((new FontLocator())->locate(), 'Die mitgelieferte Schrift fehlt.');

        return new TagRenderer(new Imagine(), new MetadataReaderWriter(), new FontLocator(), new Filesystem(), $style);
    }

    private function render(TagRenderer $renderer, int $width = 400, int $height = 120, string $text = 'KI-generiert'): string
    {
        $this->assertTrue($this->renderInto($renderer, $width, $height, $text));

        return (string) $this->file;
    }

    /**
     * Breite und Hoehe der schwarzen Flaeche in einem sonst weissen Bild.
     *
     * @return array{int, int}
     */
    private function darkBoxSize(string $file): array
    {
        // Direkt ueber GD: Imagines getColorAt() je Pixel waere hier um
        // Groessenordnungen langsamer.
        $image = imagecreatefrompng($file);
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $maxX = 0;
        $minY = $height;
        $maxY = 0;

        for ($x = 0; $x < $width; ++$x) {
            for ($y = 0; $y < $height; ++$y) {
                if ((imagecolorat($image, $x, $y) >> 16 & 0xFF) < 60) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return [$maxX - $minX + 1, $maxY - $minY + 1];
    }

    /**
     * Ein Bild mit Rauschen: nur dort unterscheidet sich die Dateigroesse bei
     * verschiedener JPEG-Qualitaet spuerbar.
     */
    private function noisyImage(int $width, int $height): ImageInterface
    {
        $image = (new Imagine())->create(new Box($width, $height), (new RGB())->color('#808080', 100));
        $draw = $image->draw();
        mt_srand(7);

        for ($i = 0; $i < 2500; ++$i) {
            $draw->ellipse(
                new Point(random_int(0, $width), random_int(0, $height)),
                new Box(random_int(3, 18), random_int(3, 18)),
                (new RGB())->color(\sprintf('#%06x', random_int(0, 0xFFFFFF)), 100),
                true,
            );
        }

        return $image;
    }

    private function renderInto(TagRenderer $renderer, int $width, int $height, string $text = 'KI-generiert', string $shortText = ''): bool
    {
        $imagine = new Imagine();
        $this->file = tempnam(sys_get_temp_dir(), 'aitag').'.png';
        $imagine->create(new Box($width, $height), (new RGB())->color('#ffffff', 100))->save($this->file);

        return $renderer->apply(
            $this->file,
            new AiTagOptions('de', AiTagOptions::POSITION_TOP_LEFT, $text, 90, 'test', $shortText),
            [],
        );
    }

    /**
     * @return list<int>
     */
    private function luminanceLevels(string $file): array
    {
        $image = (new Imagine())->open($file);
        $size = $image->getSize();
        $levels = [];

        for ($x = 0; $x < $size->getWidth(); ++$x) {
            for ($y = 0; $y < $size->getHeight(); ++$y) {
                $color = $image->getColorAt(new Point($x, $y));
                $levels[$color->getValue(ColorInterface::COLOR_RED)] = true;
            }
        }

        return array_map(intval(...), array_keys($levels));
    }
}
