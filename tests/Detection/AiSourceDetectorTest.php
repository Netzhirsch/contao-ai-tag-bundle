<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Detection;

use Contao\Image\Metadata\MetadataReaderWriter;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceDetector;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceSignal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Geprueft wird gegen echte Dateien mit echten Metadatenbloecken - eine Attrappe
 * des Lesers wuerde genau das nicht zeigen, worauf es ankommt: ob Contaos Parser
 * die Felder ueberhaupt herausgibt.
 */
class AiSourceDetectorTest extends TestCase
{
    private string|null $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    #[DataProvider('digitalSourceTypeProvider')]
    public function testRecognisesTheIptcDeclaration(string $value, string $expectedDetail): void
    {
        $signal = $this->detect($this->xmp(['Iptc4xmpExt:DigitalSourceType' => $value]));

        $this->assertNotNull($signal);
        $this->assertSame(AiSourceSignal::DECLARED, $signal->confidence);
        $this->assertSame('DigitalSourceType', $signal->source);
        $this->assertSame($expectedDetail, $signal->detail);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function digitalSourceTypeProvider(): iterable
    {
        yield 'vollstaendig KI' => [
            'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
            'trainedAlgorithmicMedia',
        ];

        yield 'teilweise KI' => [
            'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia',
            'compositeWithTrainedAlgorithmicMedia',
        ];
    }

    public function testIgnoresOtherDigitalSourceTypes(): void
    {
        // Eine gewoehnliche Fotografie deklariert sich ebenfalls - nur eben anders.
        $signal = $this->detect($this->xmp([
            'Iptc4xmpExt:DigitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture',
        ]));

        $this->assertNull($signal);
    }

    public function testTreatsAGeneratorNameAsAHintOnly(): void
    {
        $signal = $this->detect($this->xmp(['xmp:CreatorTool' => 'Midjourney v6']));

        $this->assertNotNull($signal);
        $this->assertSame(AiSourceSignal::HINT, $signal->confidence);
        $this->assertFalse($signal->isDeclared(), 'Ein Programmname ist ein Indiz, keine Erklaerung.');
        $this->assertSame('CreatorTool', $signal->source);
    }

    public function testTheDeclarationOutranksTheHint(): void
    {
        $signal = $this->detect($this->xmp([
            'xmp:CreatorTool' => 'Midjourney',
            'Iptc4xmpExt:DigitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
        ]));

        $this->assertNotNull($signal);
        $this->assertTrue($signal->isDeclared());
    }

    public function testReturnsNothingForAnOrdinaryImage(): void
    {
        $this->assertNull($this->detect(null));
    }

    public function testReturnsNothingForAMissingFile(): void
    {
        $detector = new AiSourceDetector(new MetadataReaderWriter());

        $this->assertNull($detector->detect(sys_get_temp_dir().'/aitag-does-not-exist.jpg'));
    }

    /**
     * @param string|null $attributes Fertiges XMP-Paket oder null fuer ein Bild ohne
     *                                Metadaten
     */
    private function detect(string|null $attributes): AiSourceSignal|null
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('Die Pruefung braucht die GD-Erweiterung, um ein JPEG zu erzeugen.');
        }

        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        if (null !== $attributes) {
            // XMP wird als APP1-Segment direkt hinter dem SOI-Marker erwartet
            $payload = "http://ns.adobe.com/xap/1.0/\0".$attributes;
            $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($payload) + 2).$payload.substr($jpeg, 2);
        }

        $this->file = tempnam(sys_get_temp_dir(), 'aitag').'.jpg';
        file_put_contents($this->file, $jpeg);

        return (new AiSourceDetector(new MetadataReaderWriter()))->detect($this->file);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function xmp(array $attributes): string
    {
        $serialised = '';

        foreach ($attributes as $name => $value) {
            $serialised .= \sprintf(' %s="%s"', $name, htmlspecialchars($value, ENT_QUOTES));
        }

        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about=""'
            .' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
            .' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
            .$serialised.'/>'
            .'</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }
}
