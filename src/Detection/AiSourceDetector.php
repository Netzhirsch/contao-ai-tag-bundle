<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Detection;

use Contao\Image\Metadata\ExifFormat;
use Contao\Image\Metadata\ImageMetadata;
use Contao\Image\Metadata\MetadataReaderWriter;
use Contao\Image\Metadata\PngFormat;
use Contao\Image\Metadata\XmpFormat;
use Psr\Log\LoggerInterface;

/**
 * Liest, was eine Bilddatei ueber ihre eigene Herkunft mitbringt.
 *
 * Das ist ausdruecklich keine Bildanalyse: es wird nur ausgewertet, was der
 * erzeugende Dienst in die Metadaten geschrieben hat. Metadaten ueberleben
 * Screenshots, Messenger und viele Exporte nicht - die Erkennung ist ein Netz
 * gegen das Vergessen, kein Beweis fuer das Gegenteil.
 */
final class AiSourceDetector
{
    /**
     * Der IPTC-Standard fuer die Herkunft digitaler Inhalte. C2PA und die grossen
     * Generatoren schreiben ihn; er ist die einzige verbindliche Aussage.
     */
    private const XMP_IPTC_EXT = 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/';

    private const XMP_BASIC = 'http://ns.adobe.com/xap/1.0/';

    private const XMP_PHOTOSHOP = 'http://ns.adobe.com/photoshop/1.0/';

    private const XMP_DC = 'http://purl.org/dc/elements/1.1/';

    /**
     * Werte des IPTC-Vokabulars, die eine KI-Herkunft erklaeren.
     */
    private const DIGITAL_SOURCE_TYPES = [
        'trainedalgorithmicmedia' => 'trainedAlgorithmicMedia',
        'compositewithtrainedalgorithmicmedia' => 'compositeWithTrainedAlgorithmicMedia',
        'algorithmicmedia' => 'algorithmicMedia',
    ];

    /**
     * Programmnamen, die auf einen Generator hindeuten. Nur ein Indiz - der Name kann
     * auch von einer Bildbearbeitung stammen, die das Feld weiterreicht.
     */
    private const GENERATORS = [
        'midjourney',
        'dall-e',
        'dall·e',
        'openai',
        'stable diffusion',
        'stablediffusion',
        'automatic1111',
        'comfyui',
        'firefly',
        'ideogram',
        'leonardo.ai',
        'nightcafe',
        'craiyon',
        'recraft',
        'imagen',
        'flux',
        'bing image creator',
        'designer.microsoft',
    ];

    public function __construct(
        private readonly MetadataReaderWriter $metadataReaderWriter,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function detect(string $absolutePath): AiSourceSignal|null
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        try {
            $metadata = $this->metadataReaderWriter->parse($absolutePath);
        } catch (\Throwable $exception) {
            // Kein oder kaputtes Metadatenpaket ist der Normalfall, kein Fehler.
            $this->logger?->debug(\sprintf('Metadaten von "%s" nicht lesbar: %s', $absolutePath, $exception->getMessage()));

            return null;
        }

        return $this->fromDigitalSourceType($metadata)
            ?? $this->fromGeneratorName($metadata)
            ?? $this->fromPngText($metadata);
    }

    private function fromDigitalSourceType(ImageMetadata $metadata): AiSourceSignal|null
    {
        $values = $metadata->getFormat(XmpFormat::NAME)[self::XMP_IPTC_EXT]['DigitalSourceType'] ?? null;

        foreach ($this->flatten($values) as $value) {
            // Der Wert ist eine URI; entscheidend ist ihr letzter Abschnitt.
            $type = strtolower(substr((string) strrchr('/'.$value, '/'), 1));

            if (isset(self::DIGITAL_SOURCE_TYPES[$type])) {
                return new AiSourceSignal(AiSourceSignal::DECLARED, 'DigitalSourceType', self::DIGITAL_SOURCE_TYPES[$type]);
            }
        }

        return null;
    }

    private function fromGeneratorName(ImageMetadata $metadata): AiSourceSignal|null
    {
        $xmp = $metadata->getFormat(XmpFormat::NAME);

        $candidates = [
            'CreatorTool' => $xmp[self::XMP_BASIC]['CreatorTool'] ?? null,
            'Credit' => $xmp[self::XMP_PHOTOSHOP]['Credit'] ?? null,
            'Creator' => $xmp[self::XMP_DC]['creator'] ?? null,
            'Software' => $metadata->getFormat(ExifFormat::NAME)['IFD0']['Software'] ?? null,
        ];

        foreach ($candidates as $source => $values) {
            foreach ($this->flatten($values) as $value) {
                if (null !== $generator = $this->matchGenerator($value)) {
                    return new AiSourceSignal(AiSourceSignal::HINT, $source, $generator);
                }
            }
        }

        return null;
    }

    /**
     * Stable Diffusion und verwandte Oberflaechen legen den Prompt in einem
     * PNG-Textblock namens "parameters" ab.
     */
    private function fromPngText(ImageMetadata $metadata): AiSourceSignal|null
    {
        $png = $metadata->getFormat(PngFormat::NAME);

        if ([] !== $this->flatten($png['parameters'] ?? null)) {
            return new AiSourceSignal(AiSourceSignal::HINT, 'PNG', 'parameters');
        }

        foreach (['Software', 'Comment', 'Description', 'Source'] as $key) {
            foreach ($this->flatten($png[$key] ?? null) as $value) {
                if (null !== $generator = $this->matchGenerator($value)) {
                    return new AiSourceSignal(AiSourceSignal::HINT, 'PNG '.$key, $generator);
                }
            }
        }

        return null;
    }

    private function matchGenerator(string $value): string|null
    {
        $needle = mb_strtolower($value);

        foreach (self::GENERATORS as $generator) {
            if (str_contains($needle, $generator)) {
                return $generator;
            }
        }

        return null;
    }

    /**
     * Metadatenwerte kommen je nach Format als Skalar, Liste oder verschachtelte
     * Liste (Sprachalternativen) zurueck.
     *
     * @return list<string>
     */
    private function flatten(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        if (!\is_array($value)) {
            return [(string) $value];
        }

        $flat = [];

        array_walk_recursive(
            $value,
            static function (mixed $item) use (&$flat): void {
                if (\is_scalar($item) && '' !== (string) $item) {
                    $flat[] = (string) $item;
                }
            },
        );

        return $flat;
    }
}
