<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use PHPUnit\Framework\TestCase;

class AiTagOptionsTest extends TestCase
{
    public function testSurvivesTheRoundTripThroughTheDeferredStorage(): void
    {
        $options = new AiTagOptions('de', AiTagOptions::POSITION_BOTTOM_RIGHT, 'KI-generiert', 80);

        // Contao legt die Imagine-Optionen als JSON ab, deshalb hier der gleiche Weg
        $restored = AiTagOptions::fromArray(json_decode(json_encode($options->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertNotNull($restored);
        $this->assertSame('de', $restored->locale);
        $this->assertSame(AiTagOptions::POSITION_BOTTOM_RIGHT, $restored->corner);
        $this->assertSame('KI-generiert', $restored->text);
        $this->assertSame(80, $restored->quality);
    }

    /**
     * Die Kurzform steht in den Nutzdaten: beim spaeteren (deferred) Erzeugen gibt es
     * keinen Request und damit keine Sprache mehr, aus der sie sich ableiten liesse.
     */
    public function testTheShortFormSurvivesTheRoundTrip(): void
    {
        $options = new AiTagOptions('de', AiTagOptions::POSITION_TOP_RIGHT, 'KI-generiert', 80, 'stil', 'KI');

        $restored = AiTagOptions::fromArray(json_decode(json_encode($options->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertNotNull($restored);
        $this->assertSame('KI', $restored->shortText);
    }

    /**
     * Bilder, die eine aeltere Fassung eingefroren hat, tragen kein "short" - sie
     * muessen weiter lesbar sein, nur eben ohne Kurzform.
     */
    public function testPayloadsFromBeforeTheShortFormStillLoad(): void
    {
        $restored = AiTagOptions::fromArray(['v' => 1, 'locale' => 'de', 'corner' => 'top-right', 'text' => 'KI-generiert', 'quality' => 80, 'style' => 'x']);

        $this->assertNotNull($restored);
        $this->assertSame('', $restored->shortText);
    }

    public function testTheShortFormIsPartOfTheCacheKeyPayload(): void
    {
        $with = new AiTagOptions('de', AiTagOptions::POSITION_TOP_RIGHT, 'KI-generiert', 80, 'stil', 'KI');
        $without = new AiTagOptions('de', AiTagOptions::POSITION_TOP_RIGHT, 'KI-generiert', 80, 'stil', '');

        $this->assertNotSame(implode(',', $with->toArray()), implode(',', $without->toArray()));
    }

    /**
     * Leer und Unbekanntes gelten als "generiert": so war jede Markierung vor der
     * Art, und ein unbekannter Wert darf nie eine Bearbeitung vortaeuschen.
     */
    public function testNormalisesTheKindTowardsGenerated(): void
    {
        $this->assertSame(AiTagOptions::KIND_MODIFIED, AiTagOptions::normaliseKind('modified'));
        $this->assertSame(AiTagOptions::KIND_GENERATED, AiTagOptions::normaliseKind('generated'));
        $this->assertSame(AiTagOptions::KIND_GENERATED, AiTagOptions::normaliseKind(''));
        $this->assertSame(AiTagOptions::KIND_GENERATED, AiTagOptions::normaliseKind(null));
        $this->assertSame(AiTagOptions::KIND_GENERATED, AiTagOptions::normaliseKind('Modified'));
        $this->assertSame(AiTagOptions::KIND_GENERATED, AiTagOptions::normaliseKind(['modified']));
    }

    public function testTextIsPartOfTheCacheKeyPayload(): void
    {
        $german = new AiTagOptions('de', AiTagOptions::POSITION_TOP_RIGHT, 'KI-generiert', 80);
        $english = new AiTagOptions('en', AiTagOptions::POSITION_TOP_RIGHT, 'AI-generated', 80);

        // Contao hasht die Imagine-Optionen fuer den Cache-Pfad genau so
        $this->assertNotSame(
            implode(',', $german->toArray()),
            implode(',', $english->toArray()),
            'Sprachen muessen zu unterschiedlichen Cache-Dateien fuehren.',
        );
    }

    public function testRejectsForeignAndOutdatedPayloads(): void
    {
        $this->assertNull(AiTagOptions::fromArray(null));
        $this->assertNull(AiTagOptions::fromArray('KI-generiert'));
        $this->assertNull(AiTagOptions::fromArray(['v' => 0, 'locale' => 'de', 'corner' => 'top-right', 'text' => 'x', 'quality' => 80]));
        $this->assertNull(AiTagOptions::fromArray(['v' => 1, 'locale' => 'de']));
    }
}
