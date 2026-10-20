<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Routing\PageFinder;
use Contao\DataContainer;
use Contao\Image\Metadata\MetadataReaderWriter;
use Doctrine\DBAL\Connection;
use Imagine\Image\ImagineInterface;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer\FilesAiTagListener;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use Netzhirsch\ContaoAiTagBundle\Image\FontLocator;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Hinweise in der Dateiverwaltung sind die einzige Stelle, an der die
 * Redaktion erfaehrt, dass eine markierte Datei gerade NICHT gekennzeichnet
 * ausgeliefert wird.
 *
 * Anlass: auf einer Installation kam der rote Lizenz-Hinweis nie. Zwei Luecken:
 * er kam nur beim Umschalten (wer eine schon markierte Datei erneut speicherte,
 * sah nichts), und eine fehlende Schrift stand ausschliesslich im Log.
 */
class FilesAiTagListenerTest extends TestCase
{
    private const PATH = 'files/bild.jpg';

    private const ERROR_BAG = 'contao.BE.error';

    private string $projectDir;

    private Session $session;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ai-tag-files-listener-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/files', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * Der Kern des Befunds: die Datei war schon markiert, die Redaktion speichert sie
     * erneut - und bekam keinen Hinweis, obwohl nichts eingebrannt wurde.
     */
    public function testWarnsAboutAMissingLicenceEvenWhenTheFlagIsUnchanged(): void
    {
        $listener = $this->listener($this->closedGate(), __FILE__, '1');

        $listener->onSaveFlag('1', $this->container());

        $this->assertContains('netzhirsch_ai_tag.license.hint.inactive', $this->errors());
    }

    /**
     * Ein erneutes Speichern ist kein Vorgang fuer den Nachweis: protokolliert wird
     * weiterhin nur eine echte Aenderung.
     */
    public function testAnUnchangedFlagIsNotLoggedAgain(): void
    {
        $connection = $this->connection('1');
        $connection
            ->expects($this->never())
            ->method('insert')
        ;

        $this->listener($this->closedGate(), __FILE__, '1', $connection)->onSaveFlag('1', $this->container());
    }

    public function testAChangedFlagIsLoggedOnce(): void
    {
        $connection = $this->connection('0');
        $connection
            ->expects($this->once())
            ->method('insert')
        ;

        $this->listener($this->closedGate(), __FILE__, '0', $connection)->onSaveFlag('1', $this->container());
    }

    /**
     * Mit gueltiger Lizenz, aber ohne Schrift wird ebenfalls nichts eingebrannt - das
     * stand bisher nur im Log.
     */
    public function testWarnsAboutAMissingFontEvenWithAValidLicence(): void
    {
        $listener = $this->listener($this->openGate(), '/gibt/es/nicht.ttf', '1');

        $listener->onSaveFlag('1', $this->container());

        $this->assertContains('netzhirsch_ai_tag.hint.font', $this->errors());
        $this->assertNotContains('netzhirsch_ai_tag.license.hint.inactive', $this->errors());
    }

    /**
     * Alles da: Lizenz und Schrift. Dann darf kein Fehler erscheinen - ein Hinweis,
     * der immer kommt, wird weggeklickt.
     */
    public function testStaysSilentWhenLicenceAndFontArePresent(): void
    {
        $this->listener($this->openGate(), __FILE__, '1')->onSaveFlag('1', $this->container());

        $this->assertSame([], $this->errors());
    }

    /**
     * Beim Entfernen der Markierung gibt es nichts zu warnen.
     */
    public function testRemovingTheFlagWarnsAboutNothing(): void
    {
        $this->listener($this->closedGate(), '/gibt/es/nicht.ttf', '1')->onSaveFlag('0', $this->container());

        $this->assertSame([], $this->errors());
    }

    /**
     * @return list<string>
     */
    private function errors(): array
    {
        return array_values($this->session->getFlashBag()->peek(self::ERROR_BAG));
    }

    private function container(): DataContainer
    {
        $container = $this->createStub(DataContainer::class);
        $container->id = self::PATH;

        return $container;
    }

    private function connection(string $currentFlag): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturn($currentFlag)
        ;

        $connection
            ->method('fetchAssociative')
            ->willReturn(['netzhirschAiTagPosition' => AiTagOptions::POSITION_TOP_RIGHT, 'netzhirschAiTagText' => ''])
        ;

        return $connection;
    }

    private function listener(LicenseGate $gate, string $fontPath, string $currentFlag, Connection|null $connection = null): FilesAiTagListener
    {
        $connection ??= $this->connection($currentFlag);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

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

        // Die Datei liegt nicht auf der Platte: getimagesize() scheitert, die Pruefung
        // der Lesbarkeit entfaellt, und der Test sieht nur Lizenz- und Schrifthinweise.
        $renderer = new TagRenderer(
            $this->createStub(ImagineInterface::class),
            $this->createStub(MetadataReaderWriter::class),
            new FontLocator(null, $fontPath),
            new Filesystem(),
            new TagStyle(),
        );

        return new FilesAiTagListener(
            $connection,
            new AiTagAuditLogger($connection, $this->createStub(Security::class), $translator),
            $resolver,
            $renderer,
            $gate,
            $requestStack,
            $translator,
            $this->projectDir,
        );
    }

    private function openGate(): LicenseGate
    {
        // Ohne einkompilierten Schluessel ist die Fassung nicht lizenzpflichtig.
        return new LicenseGate(new LicenseToken(''), new LicenseStore($this->projectDir), new RequestStack());
    }

    private function closedGate(): LicenseGate
    {
        $publicKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        // Schluessel vorhanden, aber kein Token: das Gate ist scharf und geschlossen.
        return new LicenseGate(new LicenseToken($publicKey), new LicenseStore($this->projectDir), new RequestStack());
    }
}
