<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\DC_Folder;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer\FilesAiTagPaletteListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Art, Position, Text, Vorschau und Abdeckung stehen nur im Formular, wenn der
 * Eintrag markiert ist.
 *
 * Anlass: als Unterpalette waren die Felder in der Dateiverwaltung nie zu sehen -
 * DC_Folder wertet Unterpaletten auf keiner unterstuetzten Contao-Version aus.
 */
class FilesAiTagPaletteListenerTest extends TestCase
{
    private const PALETTE = 'name,protected,syncExclude,netzhirschAiTagDetection,netzhirschAiGenerated,importantPartX;meta';

    public function testAddsTheFieldsBehindTheFlagOfAMarkedFile(): void
    {
        $palette = $this->listener(storedFlag: '1')(self::PALETTE, $this->container());

        $this->assertSame(
            'name,protected,syncExclude,netzhirschAiTagDetection,netzhirschAiGenerated,'.FilesAiTagPaletteListener::FIELDS.',importantPartX;meta',
            $palette,
        );
    }

    public function testLeavesAnUnmarkedFileAlone(): void
    {
        $this->assertSame(self::PALETTE, $this->listener(storedFlag: '')(self::PALETTE, $this->container()));
    }

    /**
     * Das Haekchen schickt das Formular ab: dann gilt, was abgeschickt wurde, nicht
     * der gespeicherte Stand - in beide Richtungen.
     */
    public function testTheSubmittedFormWinsOverTheDatabase(): void
    {
        $justTicked = $this->listener(storedFlag: '', post: ['FORM_SUBMIT' => 'tl_files', 'netzhirschAiGenerated' => '1']);
        $this->assertStringContainsString('netzhirschAiKind', $justTicked(self::PALETTE, $this->container()));

        // Ein leeres Haekchen schickt Contao als verstecktes Feld mit "" mit.
        $justUnticked = $this->listener(storedFlag: '1', post: ['FORM_SUBMIT' => 'tl_files', 'netzhirschAiGenerated' => '']);
        $this->assertSame(self::PALETTE, $justUnticked(self::PALETTE, $this->container()));
    }

    /**
     * Ohne Recht auf den Schalter steht er nicht in der Palette - dann auch keine
     * Felder dahinter. Und stehen sie schon drin, nicht doppelt.
     */
    public function testNeedsTheFlagAndAddsNothingTwice(): void
    {
        $listener = $this->listener(storedFlag: '1');

        $this->assertSame('name,protected;meta', $listener('name,protected;meta', $this->container()));

        $once = $listener(self::PALETTE, $this->container());
        $this->assertSame($once, $listener($once, $this->container()));
    }

    /**
     * @param array<string, string> $post
     */
    private function listener(string $storedFlag, array $post = []): FilesAiTagPaletteListener
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturn($storedFlag)
        ;

        $requestStack = new RequestStack();
        $requestStack->push(new Request([], $post));

        return new FilesAiTagPaletteListener($connection, $requestStack);
    }

    private function container(): DataContainer
    {
        // Der Listener liest nur $dc->id. Eine Attrappe von DataContainer gaebe die ID nicht
        // zurueck (__get waere mitgestubbt), ein echter DC_Folder braeuchte Contao.
        $container = (new \ReflectionClass(DC_Folder::class))->newInstanceWithoutConstructor();
        $container->id = 'files/ki/bild.jpg';

        return $container;
    }
}
