<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Contao\CoreBundle\Routing\PageFinder;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Image\DeferredImageStorageInterface;
use Contao\Image\DeferredResizerInterface;
use Contao\Image\ImageDimensions;
use Contao\Image\ImageInterface;
use Contao\Image\ImportantPart;
use Contao\Image\Metadata\MetadataReaderWriter;
use Contao\Image\ResizeConfiguration;
use Contao\Image\ResizeOptions;
use Doctrine\DBAL\Connection;
use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResizer;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use Netzhirsch\ContaoAiTagBundle\Image\FontLocator;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Resizer ist die einzige Stelle, an der die Lizenz ueber die Kennzeichnung
 * entscheidet. Deshalb wird hier festgehalten, dass genau dort geprueft wird -
 * und nicht im Resolver, an dem auch die barrierefreie Textalternative haengt.
 */
class AiTagResizerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ai-tag-resizer-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/files', 0o777, true);
    }

    protected function tearDown(): void
    {
        // Das scharfe Gate schreibt seine High-Water-Mark nach var/, das Verzeichnis ist
        // also nicht zwangslaeufig leer.
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * Mit gueltiger Lizenz reicht der Decorator die Kennzeichnung als eingefrorene
     * Imagine-Option nach unten - daran haengt der gesamte Deferred-Pfad.
     */
    public function testWithAnActiveLicenceTheTagIsFrozenIntoTheOptions(): void
    {
        $captured = null;
        $resizer = $this->resizer($this->openGate(), $captured);

        $resizer->resize($this->image(), new ResizeConfiguration(), new ResizeOptions());

        $this->assertNotNull($captured);
        $this->assertArrayHasKey(AiTagOptions::OPTION_KEY, $captured->getImagineOptions());
        $this->assertSame('KI-generiert', $captured->getImagineOptions()[AiTagOptions::OPTION_KEY]['text']);
    }

    /**
     * Ohne Lizenz entsteht das Bild wie ohne dieses Bundle: keine Kennzeichnung in
     * den Optionen, also auch kein zweiter Cache-Eintrag und keine Nachbearbeitung.
     */
    public function testWithoutAnActiveLicenceNothingIsBurntIn(): void
    {
        $captured = null;
        $resizer = $this->resizer($this->closedGate(), $captured);
        $options = new ResizeOptions();

        $resizer->resize($this->image(), new ResizeConfiguration(), $options);

        $this->assertSame($options, $captured, 'Die Optionen muessen unveraendert weitergegeben werden.');
        $this->assertArrayNotHasKey(AiTagOptions::OPTION_KEY, $captured->getImagineOptions());
    }

    /**
     * Ein unbrauchbares Token in license.json - abgeschnitten, halb geschrieben, von
     * Hand verpfuscht - darf die Bildauslieferung nicht anfassen. Es fuehrt zu keiner
     * Kennzeichnung (die Lizenz gilt nicht), aber das Bild entsteht.
     */
    public function testACorruptTokenNeitherLabelsNorBreaksImageDelivery(): void
    {
        $store = new LicenseStore($this->projectDir);
        $store->setToken('kaputt.token');

        $keypair = sodium_crypto_sign_keypair();
        $gate = new LicenseGate(
            new LicenseToken(base64_encode(sodium_crypto_sign_publickey($keypair))),
            $store,
            new RequestStack(),
        );

        $captured = null;
        $image = $this->image();
        $options = new ResizeOptions();

        $result = $this->resizer($gate, $captured)->resize($image, new ResizeConfiguration(), $options);

        $this->assertSame($image, $result, 'Das Bild muss trotzdem geliefert werden.');
        $this->assertSame($options, $captured);
        $this->assertArrayNotHasKey(AiTagOptions::OPTION_KEY, $captured->getImagineOptions());
    }

    private function openGate(): LicenseGate
    {
        // Ohne einkompilierten Schluessel ist die Fassung nicht lizenzpflichtig.
        return new LicenseGate(new LicenseToken(''), new LicenseStore($this->projectDir), new RequestStack());
    }

    private function closedGate(): LicenseGate
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        // Schluessel vorhanden, aber kein Token: das Gate ist scharf und geschlossen.
        return new LicenseGate(new LicenseToken($publicKey), new LicenseStore($this->projectDir), new RequestStack());
    }

    private function resizer(LicenseGate $gate, ResizeOptions|null &$captured): AiTagResizer
    {
        // DeferredResizerInterface erweitert ResizerInterface, eine Attrappe davon
        // erfuellt also den Schnittmengen-Typ des Decorators.
        $inner = $this->createMock(DeferredResizerInterface::class);
        $inner
            ->method('resize')
            ->willReturnCallback(
                static function (ImageInterface $image, ResizeConfiguration $configuration, ResizeOptions $options) use (&$captured): ImageInterface {
                    $captured = $options;

                    return $image;
                },
            )
        ;

        return new AiTagResizer(
            $inner,
            $this->resolver(),
            $this->renderer(),
            $gate,
            $this->createStub(DeferredImageStorageInterface::class),
            $this->createStub(ScopeMatcher::class),
            new RequestStack(),
            $this->projectDir.'/assets/images',
            $this->projectDir.'/files',
        );
    }

    /**
     * Ein echter Resolver ueber einer Attrappe der Datenbank: die Datei ist als
     * KI-generiert markiert, es liegt also am Gate, ob gekennzeichnet wird.
     */
    private function resolver(): AiTagResolver
    {
        // Genau die Methode attrappieren, die der Resolver aufruft: was eine Attrappe
        // ohne Vorgabe zurueckgibt, haengt an der Signatur - und die unterscheidet sich
        // zwischen DBAL 3 (Contao 5.3) und DBAL 4 (Contao 5.7).
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

        return new AiTagResolver(
            $connection,
            new RequestStack(),
            $this->createStub(PageFinder::class),
            $translator,
            new CornerSelector(),
            new TagStyle(),
            $this->projectDir,
            'files',
        );
    }

    private function renderer(): TagRenderer
    {
        return new TagRenderer(
            $this->createStub(ImagineInterface::class),
            $this->createStub(MetadataReaderWriter::class),
            new FontLocator(),
            new Filesystem(),
            new TagStyle(),
        );
    }

    private function image(): ImageInterface
    {
        $image = $this->createStub(ImageInterface::class);
        $image
            ->method('getPath')
            ->willReturn($this->projectDir.'/files/bild.jpg')
        ;

        $image
            ->method('getImportantPart')
            ->willReturn(new ImportantPart())
        ;

        $image
            ->method('getDimensions')
            ->willReturn(new ImageDimensions(new Box(1200, 800)))
        ;

        return $image;
    }
}
