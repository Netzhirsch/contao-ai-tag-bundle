<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Contao\Image\Metadata\MetadataReaderWriter;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
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

    public function testNothingIsDrawnBelowTheConfiguredMinimumSize(): void
    {
        $renderer = $this->renderer(new TagStyle(minWidth: 800));

        $this->assertFalse($this->renderInto($renderer, 400, 200));
    }

    private function renderer(TagStyle $style): TagRenderer
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('Die Zeichenpruefung braucht die GD-Erweiterung.');
        }

        if (null === (new FontLocator())->locate()) {
            $this->markTestSkipped('Auf diesem System ist keine TrueType-Schrift auffindbar.');
        }

        return new TagRenderer(new Imagine(), new MetadataReaderWriter(), new FontLocator(), new Filesystem(), $style);
    }

    private function render(TagRenderer $renderer): string
    {
        $this->assertTrue($this->renderInto($renderer, 400, 120));

        return (string) $this->file;
    }

    private function renderInto(TagRenderer $renderer, int $width, int $height): bool
    {
        $imagine = new Imagine();
        $this->file = tempnam(sys_get_temp_dir(), 'aitag').'.png';
        $imagine->create(new Box($width, $height), (new RGB())->color('#ffffff', 100))->save($this->file);

        return $renderer->apply(
            $this->file,
            new AiTagOptions('de', AiTagOptions::POSITION_TOP_LEFT, 'KI-generiert', 90, 'test'),
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
