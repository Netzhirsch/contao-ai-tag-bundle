<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\EventListener;

use Contao\CoreBundle\Event\FileMetadataEvent;
use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Routing\PageFinder;
use Doctrine\DBAL\Connection;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Palette\RGB;
use Netzhirsch\ContaoAiTagBundle\EventListener\FileMetadataHintListener;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use Netzhirsch\ContaoAiTagBundle\Twig\AiTagExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Alt-Text bekommt den Hinweis dort, wo Contao die Metadaten einer Datei bestimmt -
 * und damit auch in Templates, an denen die Figure-Komponente nie vorbeilaeuft.
 *
 * Anlass: auf einer Uebersichtsseite (Nachrichtenliste mit eigenem Template)
 * hatte dasselbe Bild keinen Hinweis im Alt-Text, im Artikel dagegen schon.
 */
class FileMetadataHintListenerTest extends TestCase
{
    private const UUID = '3b1e6c1a-6f5d-4a4c-9c37-2f0f3a7d8e01';

    private string $projectDir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('Der Test braucht die GD-Erweiterung fuer eine echte Bilddatei.');
        }

        $this->projectDir = sys_get_temp_dir().'/ai-tag-metadata-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/files', 0o777, true);

        // new Image() verlangt eine vorhandene Datei.
        (new Imagine())
            ->create(new Box(20, 20), (new RGB())->color('#ffffff', 100))
            ->save($this->projectDir.'/files/bild.png')
        ;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAppendsTheHintToAnExistingAltText(): void
    {
        $event = $this->dispatch(['alt' => 'Glasfläschchen mit Fischöl']);

        $this->assertSame('Glasfläschchen mit Fischöl – KI-generiert', $event->getMetadata()?->getAlt());
    }

    /**
     * Ein Bild ohne Alt-Text bekommt den Hinweis allein - wie im Twig-Override.
     */
    public function testAnEmptyAltTextBecomesTheHint(): void
    {
        $event = $this->dispatch(['alt' => '']);

        $this->assertSame('KI-generiert', $event->getMetadata()?->getAlt());
    }

    /**
     * Doppelt ausgegeben darf der Hinweis nie werden - weder wenn die Redaktion ihn schon
     * von Hand in den Alt-Text geschrieben hat noch wenn der Listener zweimal laeuft.
     */
    public function testDoesNotRepeatAHintThatIsAlreadyThere(): void
    {
        $event = $this->dispatch(['alt' => 'Glasfläschchen – KI-generiert']);

        $this->assertSame('Glasfläschchen – KI-generiert', $event->getMetadata()?->getAlt());
    }

    public function testLeavesTheOtherMetadataUntouched(): void
    {
        $metadata = $this->dispatch(['alt' => 'Alt', 'title' => 'Titel', 'caption' => 'Unterschrift'])->getMetadata();

        $this->assertNotNull($metadata);
        $this->assertSame('Titel', $metadata->getTitle());
        $this->assertSame('Unterschrift', $metadata->getCaption());
        $this->assertSame(self::UUID, $metadata->getUuid());
    }

    /**
     * Ein Bild, das nicht markiert ist, bleibt wie es ist.
     */
    public function testAnUnmarkedFileIsLeftAlone(): void
    {
        $event = $this->dispatch(['alt' => 'Alt'], marked: false);

        $this->assertSame('Alt', $event->getMetadata()?->getAlt());
    }

    /**
     * Ohne UUID gibt es keine Datei aus der Dateiverwaltung - und nur dort lassen
     * sich Markierungen setzen.
     */
    public function testWithoutAUuidNothingHappens(): void
    {
        $event = new FileMetadataEvent(new Metadata(['alt' => 'Alt']));

        $this->listener(true)($event);

        $this->assertSame('Alt', $event->getMetadata()?->getAlt());
    }

    public function testWithoutAnyMetadataNothingHappens(): void
    {
        $event = new FileMetadataEvent(null);

        $this->listener(true)($event);

        $this->assertNull($event->getMetadata());
    }

    /**
     * hint_placement steuert, wohin der Hinweis geht. Bei caption oder none bleibt
     * der Alt-Text unveraendert.
     */
    #[DataProvider('placementProvider')]
    public function testRespectsThePlacement(string $placement, string $expectedAlt): void
    {
        $event = $this->dispatch(['alt' => 'Alt'], placement: $placement);

        $this->assertSame($expectedAlt, $event->getMetadata()?->getAlt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function placementProvider(): iterable
    {
        yield 'alt' => [AiTagExtension::PLACEMENT_ALT, 'Alt – KI-generiert'];
        yield 'both' => [AiTagExtension::PLACEMENT_BOTH, 'Alt – KI-generiert'];
        yield 'caption' => [AiTagExtension::PLACEMENT_CAPTION, 'Alt'];
        yield 'none' => [AiTagExtension::PLACEMENT_NONE, 'Alt'];
    }

    /**
     * Eine Abbildung darf an der Kennzeichnung nie scheitern: faellt die Abfrage aus,
     * bleibt der Alt-Text, wie er ist.
     */
    public function testADatabaseFailureNeverBreaksTheImage(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchOne')
            ->willThrowException(new \RuntimeException('Datenbank weg'))
        ;

        $event = new FileMetadataEvent(new Metadata(['alt' => 'Alt', 'uuid' => self::UUID]));

        $this->listener(true, $connection)($event);

        $this->assertSame('Alt', $event->getMetadata()?->getAlt());
    }

    /**
     * @param array<string, string> $values
     */
    private function dispatch(array $values, bool $marked = true, string $placement = AiTagExtension::PLACEMENT_ALT): FileMetadataEvent
    {
        $event = new FileMetadataEvent(new Metadata([...$values, 'uuid' => self::UUID]));

        $this->listener($marked, null, $placement)($event);

        return $event;
    }

    private function listener(bool $marked, Connection|null $connection = null, string $placement = AiTagExtension::PLACEMENT_ALT): FileMetadataHintListener
    {
        if (null === $connection) {
            $connection = $this->createStub(Connection::class);

            // Welche Datei zur UUID gehoert, und ob sie (oder ein Ordner darueber) markiert ist.
            $connection
                ->method('fetchOne')
                ->willReturn('files/bild.png')
            ;

            $connection
                ->method('fetchAssociative')
                ->willReturn($marked ? ['netzhirschAiTagPosition' => AiTagOptions::POSITION_TOP_RIGHT, 'netzhirschAiTagText' => ''] : false)
            ;
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturn('KI-generiert')
        ;

        $resolver = new AiTagResolver(
            $connection,
            new RequestStack(),
            $this->createStub(PageFinder::class),
            $translator,
            new CornerSelector(),
            new TagStyle(),
            $this->projectDir,
            'files',
        );

        $extension = new AiTagExtension($resolver, new Imagine(), $placement, ' – ');

        return new FileMetadataHintListener($connection, $extension, $this->projectDir);
    }
}
