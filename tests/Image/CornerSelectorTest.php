<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Contao\Image\ImportantPart;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CornerSelectorTest extends TestCase
{
    #[DataProvider('importantPartProvider')]
    public function testSelectsCornerWithLeastOverlap(ImportantPart $importantPart, string $expected): void
    {
        $this->assertSame($expected, (new CornerSelector())->select($importantPart));
    }

    /**
     * @return iterable<string, array{ImportantPart, string}>
     */
    public static function importantPartProvider(): iterable
    {
        yield 'ohne wichtigen Bereich rechts oben' => [
            new ImportantPart(0.0, 0.0, 1.0, 1.0),
            AiTagOptions::POSITION_TOP_RIGHT,
        ];

        yield 'Motiv rechts oben weicht nach unten aus' => [
            new ImportantPart(0.65, 0.05, 0.3, 0.45),
            AiTagOptions::POSITION_BOTTOM_RIGHT,
        ];

        yield 'Motiv rechts unten weicht nach oben aus' => [
            new ImportantPart(0.6, 0.6, 0.4, 0.4),
            AiTagOptions::POSITION_TOP_RIGHT,
        ];

        yield 'Motiv oben ueber die ganze Breite weicht nach unten aus' => [
            new ImportantPart(0.0, 0.0, 1.0, 0.4),
            AiTagOptions::POSITION_BOTTOM_RIGHT,
        ];

        yield 'Motiv rechts ueber die ganze Hoehe weicht nach links aus' => [
            new ImportantPart(0.5, 0.0, 0.5, 1.0),
            AiTagOptions::POSITION_BOTTOM_LEFT,
        ];
    }
}
