<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Detection;

use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceSignal;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der IPTC-Wert compositeWithTrainedAlgorithmicMedia beschreibt ein Bild, in das
 * KI-Material eingefuegt wurde (Inpainting, Outpainting, generative Fuellung):
 * vorhandenes Material, teilweise mit KI bearbeitet. Die Erkennung darf es nicht
 * als vollstaendig generiert vorschlagen.
 */
class AiSourceSignalTest extends TestCase
{
    #[DataProvider('signalProvider')]
    public function testSuggestsTheKindThatMatchesTheDeclaration(AiSourceSignal $signal, string $expected): void
    {
        $this->assertSame($expected, $signal->kind());
    }

    /**
     * @return iterable<string, array{AiSourceSignal, string}>
     */
    public static function signalProvider(): iterable
    {
        yield 'komplett erzeugt' => [new AiSourceSignal(AiSourceSignal::DECLARED, 'DigitalSourceType', 'trainedAlgorithmicMedia'), AiTagOptions::KIND_GENERATED];
        yield 'mit KI-Material zusammengesetzt' => [new AiSourceSignal(AiSourceSignal::DECLARED, 'DigitalSourceType', 'compositeWithTrainedAlgorithmicMedia'), AiTagOptions::KIND_MODIFIED];
        yield 'algorithmisch erzeugt' => [new AiSourceSignal(AiSourceSignal::DECLARED, 'DigitalSourceType', 'algorithmicMedia'), AiTagOptions::KIND_GENERATED];
        yield 'Indiz aus dem Programmnamen' => [new AiSourceSignal(AiSourceSignal::HINT, 'CreatorTool', 'Midjourney'), AiTagOptions::KIND_GENERATED];
    }

    /**
     * Nur der IPTC-Wert zaehlt: ein Programmname, der zufaellig so heisst, ist
     * keine Erklaerung.
     */
    public function testOnlyTheDigitalSourceTypeCanSuggestAModification(): void
    {
        $lookalike = new AiSourceSignal(AiSourceSignal::HINT, 'CreatorTool', 'compositeWithTrainedAlgorithmicMedia');

        $this->assertSame(AiTagOptions::KIND_GENERATED, $lookalike->kind());
    }

    /**
     * Die Art wird nicht gespeichert, sondern aus dem Signal abgeleitet: ein aus der
     * Datenbank gelesenes Signal liefert dieselbe.
     */
    public function testTheKindSurvivesTheStorageRoundTrip(): void
    {
        $original = new AiSourceSignal(AiSourceSignal::DECLARED, 'DigitalSourceType', 'compositeWithTrainedAlgorithmicMedia');

        $restored = AiSourceSignal::fromStorage($original->toStorage());

        $this->assertNotNull($restored);
        $this->assertSame(AiTagOptions::KIND_MODIFIED, $restored->kind());
    }
}
