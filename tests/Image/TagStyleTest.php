<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TagStyleTest extends TestCase
{
    public function testCapsTheFontSizeOnLargeImages(): void
    {
        $style = new TagStyle(minFontSize: 11, relativeFontSize: 0.03, maxFontSize: 48);

        $this->assertSame(11, $style->fontSize(200), 'Untergrenze: 6px waeren unlesbar.');
        $this->assertSame(36, $style->fontSize(1200), 'Dazwischen gilt der relative Wert.');
        $this->assertSame(48, $style->fontSize(4000), 'Ohne Deckel waeren es 120px und das Label ein Bildelement.');
    }

    public function testMinimumDimensions(): void
    {
        $style = new TagStyle(minWidth: 320, minHeight: 200);

        $this->assertTrue($style->isTooSmall(319, 400));
        $this->assertTrue($style->isTooSmall(400, 199));
        $this->assertFalse($style->isTooSmall(320, 200));
        $this->assertFalse((new TagStyle())->isTooSmall(1, 1), 'Ohne Konfiguration greift die Pruefung nicht.');
    }

    /**
     * Der Fingerabdruck landet im Cache-Schluessel. Aendert er sich bei einer
     * Design-Aenderung nicht, behalten bereits erzeugte Bilder ihr altes Aussehen.
     */
    #[DataProvider('changedStyleProvider')]
    public function testFingerprintChangesWithEveryVisibleSetting(TagStyle $changed): void
    {
        $this->assertNotSame((new TagStyle())->fingerprint(), $changed->fingerprint());
    }

    /**
     * @return iterable<string, array{TagStyle}>
     */
    public static function changedStyleProvider(): iterable
    {
        yield 'Stil' => [new TagStyle(style: TagStyle::STYLE_OUTLINE)];
        yield 'Textfarbe' => [new TagStyle(textColor: '#ff0000')];
        yield 'Flaechenfarbe' => [new TagStyle(boxColor: '#ff0000')];
        yield 'Deckkraft' => [new TagStyle(boxOpacity: 80)];
        yield 'Eckenradius' => [new TagStyle(cornerRadius: 0.5)];
        yield 'Innenabstand' => [new TagStyle(paddingRatio: 0.6)];
        yield 'Aussenabstand' => [new TagStyle(marginRatio: 1.0)];
        yield 'Grossbuchstaben' => [new TagStyle(uppercase: true)];
        yield 'Mindestschrift' => [new TagStyle(minFontSize: 12)];
        yield 'Relative Schrift' => [new TagStyle(relativeFontSize: 0.04)];
        yield 'Maximale Schrift' => [new TagStyle(maxFontSize: 64)];
        yield 'Maximale Breite' => [new TagStyle(maxBoxWidth: 0.8)];
        yield 'Maximale Hoehe' => [new TagStyle(maxBoxHeight: 0.4)];
        yield 'Mindestbreite' => [new TagStyle(minWidth: 320)];
        yield 'Mindesthoehe' => [new TagStyle(minHeight: 200)];
    }

    public function testFingerprintIsStableForTheSameSettings(): void
    {
        $this->assertSame(
            (new TagStyle(style: TagStyle::STYLE_PLAIN, uppercase: true))->fingerprint(),
            (new TagStyle(style: TagStyle::STYLE_PLAIN, uppercase: true))->fingerprint(),
        );
    }
}
