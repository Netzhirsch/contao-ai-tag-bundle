<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Twig;

use Contao\CoreBundle\Routing\PageFinder;
use Doctrine\DBAL\Connection;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Palette\RGB;
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
 * Die Twig-Funktion netzhirsch_ai_tag_hint() steht in der Figure-Komponente, die
 * fast jede Seite mit Bild rendert. Sie darf nie werfen: auf einer Installation
 * uebergab ein Template ein stdClass, die Funktion verlangte
 * `Figure|string|null`, und die Startseite starb mit einem TypeError - wegen
 * eines Hinweises, der hoechstens fehlen darf.
 */
class AiTagExtensionTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ai-tag-extension-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/files', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * Was auch immer ein Template uebergibt: kein Fehler, kein Hinweis.
     */
    #[DataProvider('foreignValueProvider')]
    public function testNeverThrowsOnAValueItDoesNotKnow(mixed $value): void
    {
        $this->assertNull($this->extension()->getHint($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function foreignValueProvider(): iterable
    {
        // Genau der gemeldete Fall.
        yield 'stdClass' => [new \stdClass()];
        yield 'stdClass mit Pfad' => [(object) ['singleSRC' => 'files/bild.png']];
        yield 'Liste' => [['files/bild.png']];
        yield 'Zahl' => [42];
        yield 'Wahrheitswert' => [true];
        yield 'null' => [null];
        yield 'leere Zeichenkette' => [''];
        yield 'Pfad, der nicht existiert' => ['/gibt/es/nicht.png'];
    }

    public function testStillFindsTheHintForAMarkedFilePath(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('Der Test braucht die GD-Erweiterung fuer eine echte Bilddatei.');
        }

        $path = $this->projectDir.'/files/bild.png';
        (new Imagine())->create(new Box(20, 20), (new RGB())->color('#ffffff', 100))->save($path);

        $this->assertSame('KI-generiert', $this->extension()->getHint($path));
    }

    private function extension(): AiTagExtension
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn(['netzhirschAiTagPosition' => AiTagOptions::POSITION_TOP_RIGHT, 'netzhirschAiTagText' => ''])
        ;

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

        return new AiTagExtension($resolver, new Imagine());
    }
}
