<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Contao\CoreBundle\Routing\PageFinder;
use Contao\Image\ImageInterface;
use Contao\Image\ImportantPart;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Art der KI-Beteiligung bestimmt den Wortlaut: "KI-generiert" fuer ein
 * vollstaendig erzeugtes Bild, "KI-bearbeitet" fuer eines, das teilweise mit KI
 * veraendert wurde. Ein nur bearbeitetes Foto als "generiert" zu kennzeichnen
 * behauptet mehr, als stimmt.
 */
class AiTagResolverTest extends TestCase
{
    private const FILE = '/var/www/projekt/files/bild.jpg';

    private const TRANSLATIONS = [
        'netzhirsch_ai_tag.label' => 'KI-generiert',
        'netzhirsch_ai_tag.label_modified' => 'KI-bearbeitet',
        'netzhirsch_ai_tag.label_short' => 'KI',
    ];

    public function testAGeneratedLabelUsesTheGeneratedWording(): void
    {
        $options = $this->resolver($this->row(AiTagOptions::KIND_GENERATED))->resolve($this->image(), 80);

        $this->assertNotNull($options);
        $this->assertSame('KI-generiert', $options->text);
    }

    public function testAModifiedLabelUsesTheModifiedWording(): void
    {
        $options = $this->resolver($this->row(AiTagOptions::KIND_MODIFIED))->resolve($this->image(), 80);

        $this->assertNotNull($options);
        $this->assertSame('KI-bearbeitet', $options->text);
    }

    /**
     * Jede Markierung vor der Einfuehrung der Art hat keine - sie war und bleibt
     * "generiert". Auch Unbekanntes darf nie eine Bearbeitung vortaeuschen.
     */
    #[DataProvider('unknownKindProvider')]
    public function testAnEmptyOrUnknownKindCountsAsGenerated(mixed $kind): void
    {
        $options = $this->resolver($this->row($kind))->resolve($this->image(), 80);

        $this->assertNotNull($options);
        $this->assertSame('KI-generiert', $options->text);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknownKindProvider(): iterable
    {
        yield 'leer' => [''];
        yield 'null' => [null];
        yield 'unbekannt' => ['banane'];
        yield 'Grossschreibung' => ['MODIFIED'];
    }

    /**
     * Ein eigener Text an der Datei gilt fuer beide Arten - wer ihn setzt, will genau den.
     */
    #[DataProvider('kindProvider')]
    public function testACustomFileTextWinsForBothKinds(string $kind): void
    {
        $options = $this->resolver($this->row($kind, 'Mit KI erstellt'))->resolve($this->image(), 80);

        $this->assertNotNull($options);
        $this->assertSame('Mit KI erstellt', $options->text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kindProvider(): iterable
    {
        yield 'generiert' => [AiTagOptions::KIND_GENERATED];
        yield 'bearbeitet' => [AiTagOptions::KIND_MODIFIED];
    }

    /**
     * Der Wortlaut der Website gilt je Art getrennt: der fuer "generiert" darf nicht
     * auf ein nur bearbeitetes Bild geraten, sonst waere die Unterscheidung dahin.
     */
    public function testTheWebsiteWordingIsSeparatePerKind(): void
    {
        $page = $this->createStub(PageModel::class);
        $page
            ->method('row')
            ->willReturn(['netzhirschAiTagText' => 'Erzeugt mit KI', 'netzhirschAiTagTextModified' => 'Mit KI überarbeitet'])
        ;

        $finder = $this->createStub(PageFinder::class);
        $finder
            ->method('findRootPageForRequest')
            ->willReturn($page)
        ;

        $generated = $this->resolver($this->row(AiTagOptions::KIND_GENERATED), $finder)->resolve($this->image(), 80);
        $modified = $this->resolver($this->row(AiTagOptions::KIND_MODIFIED), $finder)->resolve($this->image(), 80);

        $this->assertSame('Erzeugt mit KI', $generated?->text);
        $this->assertSame('Mit KI überarbeitet', $modified?->text);
    }

    /**
     * Ist fuer "bearbeitet" nichts eingestellt, greift der Standard - nicht der
     * Wortlaut fuer "generiert".
     */
    public function testAnEmptyModifiedWordingFallsBackToTheModifiedDefault(): void
    {
        $page = $this->createStub(PageModel::class);
        $page
            ->method('row')
            ->willReturn(['netzhirschAiTagText' => 'Erzeugt mit KI', 'netzhirschAiTagTextModified' => ''])
        ;

        $finder = $this->createStub(PageFinder::class);
        $finder
            ->method('findRootPageForRequest')
            ->willReturn($page)
        ;

        $modified = $this->resolver($this->row(AiTagOptions::KIND_MODIFIED), $finder)->resolve($this->image(), 80);

        $this->assertSame('KI-bearbeitet', $modified?->text);
    }

    public function testTheShortFormComesFromTheTranslationAndTravelsInTheOptions(): void
    {
        $options = $this->resolver($this->row(AiTagOptions::KIND_MODIFIED))->resolve($this->image(), 80);

        $this->assertSame('KI', $options?->shortText);
    }

    public function testTheShortFormCanBeSwitchedOff(): void
    {
        $resolver = $this->resolver($this->row(AiTagOptions::KIND_GENERATED), null, false);

        $this->assertSame('', $resolver->shortText('de'));
        $this->assertSame('', $resolver->resolve($this->image(), 80)?->shortText);
    }

    /**
     * Gibt es fuer eine Sprache keine Kurzform, bleibt sie leer - nicht der Schluessel.
     */
    public function testAMissingShortFormStaysEmptyInsteadOfShowingTheKey(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $this->assertSame('', $this->resolver(false, null, true, $translator)->shortText('xx'));
    }

    /**
     * Fuer das Backend: was fuer diese Datei eingebrannt wuerde, mit der Art - auch
     * fuer eine noch nicht markierte Datei (dann der Standard "generiert").
     */
    public function testTextsDescribeWhatWouldBeBurnt(): void
    {
        $this->assertSame(
            ['text' => 'KI-bearbeitet', 'short' => 'KI', 'kind' => AiTagOptions::KIND_MODIFIED],
            $this->resolver($this->row(AiTagOptions::KIND_MODIFIED))->texts(self::FILE),
        );
        $this->assertSame(
            ['text' => 'KI-generiert', 'short' => 'KI', 'kind' => AiTagOptions::KIND_GENERATED],
            $this->resolver(false)->texts(self::FILE),
        );
    }

    /**
     * Nach einem Update des Bundles fehlt die Spalte der Art, bis die Datenbank
     * aktualisiert ist (contao:migrate). Ohne Rueckfall scheiterte die Abfrage, und
     * JEDES Bild verlore seine Kennzeichnung - bis jemand die Datenbank aktualisiert.
     * Dann gilt eben "generiert".
     */
    public function testALabelSurvivesAMissingKindColumn(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(
                function (string $sql): array {
                    if (str_contains($sql, 'netzhirschAiKind')) {
                        throw $this->dbalException('Unknown column');
                    }

                    return ['netzhirschAiTagPosition' => AiTagOptions::POSITION_TOP_RIGHT, 'netzhirschAiTagText' => ''];
                },
            )
        ;

        $options = $this->resolverFor($connection)->resolve($this->image(), 80);

        $this->assertNotNull($options, 'Ohne die Spalte darf die Kennzeichnung nicht verschwinden.');
        $this->assertSame('KI-generiert', $options->text);
    }

    /**
     * Gibt es die Tabelle gar nicht, wird nicht gekennzeichnet - aber auch
     * nichts gesprengt.
     */
    public function testNoSchemaAtAllNeitherLabelsNorThrows(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willThrowException($this->dbalException('no table'))
        ;

        $this->assertNull($this->resolverFor($connection)->resolve($this->image(), 80));
    }

    /**
     * Die Ausnahme, die DBAL bei einer fehlenden Spalte wirft. Der Konstruktor ist in
     * DBAL 3 und 4 derselbe; deshalb keine Weiche nach Version
     * (Doctrine\DBAL\Exception ist in 3 eine Klasse, in 4 ein Interface).
     */
    private function dbalException(string $message): \Throwable
    {
        $driver = new class($message) extends \RuntimeException implements DriverException {
            public function getSQLState(): string|null
            {
                return null;
            }
        };

        return new InvalidFieldNameException($driver, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(mixed $kind, string $text = ''): array
    {
        return ['netzhirschAiTagPosition' => AiTagOptions::POSITION_TOP_RIGHT, 'netzhirschAiTagText' => $text, 'netzhirschAiKind' => $kind];
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function resolver(array|false $row, PageFinder|null $finder = null, bool $shortFallback = true, TranslatorInterface|null $translator = null): AiTagResolver
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        return $this->resolverFor($connection, $finder, $shortFallback, $translator);
    }

    private function resolverFor(Connection $connection, PageFinder|null $finder = null, bool $shortFallback = true, TranslatorInterface|null $translator = null): AiTagResolver
    {
        if (null === $translator) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator
                ->method('trans')
                ->willReturnCallback(static fn (string $id): string => self::TRANSLATIONS[$id] ?? $id)
            ;
        }

        // Ein Request mit Sprache, damit die Seite einen Startpunkt finden kann.
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://www.kunde.de/de/'));

        return new AiTagResolver(
            $connection,
            $requestStack,
            $finder ?? $this->createStub(PageFinder::class),
            $translator,
            new CornerSelector(),
            new TagStyle(),
            '/var/www/projekt',
            'files',
            [],
            null,
            $shortFallback,
        );
    }

    private function image(): ImageInterface
    {
        $image = $this->createStub(ImageInterface::class);
        $image
            ->method('getPath')
            ->willReturn(self::FILE)
        ;

        $image
            ->method('getImportantPart')
            ->willReturn(new ImportantPart())
        ;

        return $image;
    }
}
