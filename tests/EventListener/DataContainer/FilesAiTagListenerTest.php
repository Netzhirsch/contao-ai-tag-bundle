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
     * Beim Setzen der Markierung muss das Protokoll festhalten, WELCHE Art
     * gekennzeichnet wurde: die, die mit dem Formular kommt. Der Callback des
     * Schalters laeuft vor dem der Art, sie steht also noch nicht in der Datenbank.
     */
    public function testSettingTheFlagLogsTheKindFromTheForm(): void
    {
        $connection = $this->connection('0', AiTagOptions::KIND_GENERATED);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'tl_netzhirsch_ai_tag_log',
                $this->callback(static fn (array $row): bool => 'flag_set' === $row['action'] && 'modified' === $row['kind']),
            )
        ;

        $this->listener($this->openGate(), __FILE__, '0', $connection, ['netzhirschAiKind' => 'modified'])->onSaveFlag('1', $this->container());
    }

    /**
     * Ohne mitgeschickte Art gilt die gespeicherte - und ein Unbekanntes wird "generiert".
     */
    public function testSettingTheFlagFallsBackToTheStoredKind(): void
    {
        $connection = $this->connection('0', AiTagOptions::KIND_MODIFIED);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'tl_netzhirsch_ai_tag_log',
                $this->callback(static fn (array $row): bool => 'modified' === $row['kind']),
            )
        ;

        $this->listener($this->openGate(), __FILE__, '0', $connection)->onSaveFlag('1', $this->container());
    }

    /**
     * Wer die Art einer bereits gesetzten Markierung aendert, hinterlaesst einen
     * eigenen Eintrag: aus "generiert" wird "bearbeitet" - das ist eine Aussage ueber
     * das Bild, die der Nachweis festhalten muss.
     */
    public function testChangingTheKindOfAnExistingLabelIsLogged(): void
    {
        $connection = $this->connection('1', AiTagOptions::KIND_GENERATED);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'tl_netzhirsch_ai_tag_log',
                $this->callback(static fn (array $row): bool => 'kind_changed' === $row['action'] && 'modified' === $row['kind']),
            )
        ;

        $listener = $this->listener($this->openGate(), __FILE__, '1', $connection);

        $listener->onSaveFlag('1', $this->container());
        $this->assertSame('modified', $listener->onSaveKind('modified', $this->container()));
    }

    /**
     * Setzt dasselbe Formular Schalter UND Art, steht die Art schon im Eintrag
     * "gesetzt" - ein zweiter Eintrag "Art geaendert" haelte dasselbe doppelt fest.
     */
    public function testSettingFlagAndKindTogetherWritesOnlyOneEntry(): void
    {
        $connection = $this->connection('0', AiTagOptions::KIND_GENERATED);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'tl_netzhirsch_ai_tag_log',
                $this->callback(static fn (array $row): bool => 'flag_set' === $row['action'] && 'modified' === $row['kind']),
            )
        ;

        $listener = $this->listener($this->openGate(), __FILE__, '0', $connection, ['netzhirschAiKind' => 'modified']);

        $listener->onSaveFlag('1', $this->container());
        $listener->onSaveKind('modified', $this->container());
    }

    public function testAnUnchangedKindIsNotLogged(): void
    {
        $connection = $this->connection('1', AiTagOptions::KIND_MODIFIED);
        $connection
            ->expects($this->never())
            ->method('insert')
        ;

        $this->listener($this->openGate(), __FILE__, '1', $connection)->onSaveKind('modified', $this->container());
    }

    /**
     * Die Art einer Datei ohne Markierung ist keine Aenderung, die der Nachweis
     * festhalten muss.
     */
    public function testTheKindOfAnUnlabelledFileIsNotLogged(): void
    {
        $connection = $this->connection('0', AiTagOptions::KIND_GENERATED);
        $connection
            ->expects($this->never())
            ->method('insert')
        ;

        $this->listener($this->openGate(), __FILE__, '0', $connection)->onSaveKind('modified', $this->container());
    }

    public function testASubmittedUnknownKindIsStoredAsGenerated(): void
    {
        $listener = $this->listener($this->openGate(), __FILE__, '1');

        $this->assertSame('generated', $listener->onSaveKind('banane', $this->container()));
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

    private function connection(string $currentFlag, string $currentKind = AiTagOptions::KIND_GENERATED): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);

        // currentValue() baut das SQL mit quoteIdentifier(); eine Attrappe gibt dafuer
        // sonst nichts zurueck, und die Spalte stuende nie im SQL - dann liessen sich
        // Schalter und Art nicht unterscheiden.
        $connection
            ->method('quoteIdentifier')
            ->willReturnArgument(0)
        ;

        // Die Abfragen nach dem Schalter und nach der Art laufen beide ueber fetchOne().
        $connection
            ->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): string => str_contains($sql, 'netzhirschAiKind') ? $currentKind : $currentFlag)
        ;

        $connection
            ->method('fetchAssociative')
            ->willReturn(['netzhirschAiTagPosition' => AiTagOptions::POSITION_TOP_RIGHT, 'netzhirschAiTagText' => ''])
        ;

        return $connection;
    }

    /**
     * @param array<string, string> $post was das Formular mitschickt (z. B. die gewaehlte Art)
     */
    private function listener(LicenseGate $gate, string $fontPath, string $currentFlag, Connection|null $connection = null, array $post = []): FilesAiTagListener
    {
        $connection ??= $this->connection($currentFlag);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request([], $post);
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
