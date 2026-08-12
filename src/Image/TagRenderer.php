<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Contao\Image\Metadata\ImageMetadata;
use Contao\Image\Metadata\MetadataReaderWriter;
use Imagine\Image\AbstractFont;
use Imagine\Image\ImageInterface as ImagineImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Brennt die Kennzeichnung in eine bereits fertig skalierte Bilddatei.
 *
 * Bewusst nach dem Resize und nicht davor: nur so ist die Schriftgroesse relativ
 * zur ausgelieferten Bildgroesse und damit lesbar. Nach dem Zeichnen werden die
 * per Contao-Bildgroesse konfigurierten Copyright-Metadaten wieder angewandt,
 * weil das erneute Speichern sie sonst verwirft.
 */
final readonly class TagRenderer
{
    /**
     * Qualitaets-Schluessel, die vor dem zweiten Speichern zurueckgesetzt werden,
     * damit die Zielqualitaet der Bildgroesse und nicht die angehobene erste
     * Kodierung greift.
     */
    private const array QUALITY_KEYS = [
        'quality',
        'jpeg_quality',
        'webp_quality',
        'avif_quality',
        'heic_quality',
        'jxl_quality',
        'png_compression_level',
    ];

    private const int OPAQUE = 100;

    public function __construct(
        private ImagineInterface $imagine,
        private MetadataReaderWriter $metadataReaderWriter,
        private FontLocator $fontLocator,
        private Filesystem $filesystem,
        private LoggerInterface|null $logger = null,
        private int $minFontSize = 11,
        private float $relativeFontSize = 0.03,
        private float $maxBoxWidth = 0.65,
        private float $maxBoxHeight = 0.30,
        private int $boxOpacity = 60,
    ) {
    }

    /**
     * @param array<string, mixed>        $imagineOptions    Optionen des Resize-Vorgangs
     * @param array<string, array<mixed>> $preserveCopyright Metadatenfelder der Bildgroesse
     *
     * @return bool true, wenn die Kennzeichnung eingebrannt wurde
     */
    public function apply(string $path, AiTagOptions $tag, array $imagineOptions, array $preserveCopyright = []): bool
    {
        $font = $this->fontLocator->locate();

        if (null === $font) {
            return false;
        }

        if (!is_file($path) || !is_writable($path)) {
            $this->logger?->warning(\sprintf('Bilddatei "%s" ist fuer die KI-Kennzeichnung nicht schreibbar.', $path));

            return false;
        }

        try {
            $image = $this->imagine->open($path);
        } catch (\Throwable $exception) {
            $this->logger?->warning(\sprintf('Bild "%s" konnte fuer die KI-Kennzeichnung nicht geoeffnet werden: %s', $path, $exception->getMessage()));

            return false;
        }

        if (!$this->draw($image, $tag, $font, $path)) {
            // Nicht gezeichnet: die Datei traegt noch die angehobene erste Kodierung,
            // deshalb trotzdem mit der Zielqualitaet neu speichern.
            $this->save($image, $path, $tag, $imagineOptions, $preserveCopyright);

            return false;
        }

        $this->save($image, $path, $tag, $imagineOptions, $preserveCopyright);

        return true;
    }

    /**
     * Prueft ohne zu zeichnen, ob die Kennzeichnung in der angegebenen Groesse lesbar
     * waere. Grundlage fuer die Backend-Warnung bei zu kleinen Bildgroessen.
     */
    public function isLegible(int $width, int $height, string $text): bool
    {
        $font = $this->fontLocator->locate();

        if (null === $font) {
            return false;
        }

        $fontSize = $this->fontSize($width);
        $box = $this->imagine->font($font, $fontSize, (new RGB())->color('#000000', self::OPAQUE))->box($text);
        $padding = (int) round($fontSize * 0.45);

        return $box->getWidth() + 2 * $padding <= $width * $this->maxBoxWidth
            && $box->getHeight() + 2 * $padding <= $height * $this->maxBoxHeight;
    }

    private function draw(ImagineImageInterface $image, AiTagOptions $tag, string $font, string $path): bool
    {
        $width = $image->getSize()->getWidth();
        $height = $image->getSize()->getHeight();
        $fontSize = $this->fontSize($width);

        $palette = $image->palette();
        $corner = AiTagOptions::POSITION_AUTO === $tag->corner ? AiTagOptions::POSITION_TOP_RIGHT : $tag->corner;
        $isDarkBackground = $this->averageLuminance($image, $corner) < 128;

        $textColor = $palette->color($isDarkBackground ? '#ffffff' : '#000000', self::OPAQUE);
        $boxColor = $palette->color($isDarkBackground ? '#000000' : '#ffffff', $this->boxOpacity);

        $fontObject = $this->imagine->font($font, $fontSize, $textColor);

        // Imagines eigene Schnittstellen passen nicht zusammen: font() liefert ein
        // FontInterface, text() verlangt eine AbstractFont. Alle mitgelieferten Treiber
        // liefern AbstractFont - ein fremder Treiber koennte es nicht.
        if (!$fontObject instanceof AbstractFont) {
            $this->logger?->warning(\sprintf('Der Imagine-Treiber liefert eine Schrift vom Typ %s, mit der nicht gezeichnet werden kann.', $fontObject::class));

            return false;
        }

        $textBox = $fontObject->box($tag->text);
        $padding = (int) round($fontSize * 0.45);

        $boxWidth = $textBox->getWidth() + 2 * $padding;
        $boxHeight = $textBox->getHeight() + 2 * $padding;

        if ($boxWidth > $width * $this->maxBoxWidth || $boxHeight > $height * $this->maxBoxHeight) {
            $this->logger?->info(\sprintf(
                'KI-Kennzeichnung fuer "%s" ausgelassen: bei %dx%d px passt das Label (%dx%d px) nicht lesbar ins Bild.',
                $path,
                $width,
                $height,
                $boxWidth,
                $boxHeight,
            ));

            return false;
        }

        $margin = (int) round($fontSize * 0.5);

        [$x, $y] = match ($corner) {
            AiTagOptions::POSITION_TOP_LEFT => [$margin, $margin],
            AiTagOptions::POSITION_BOTTOM_LEFT => [$margin, $height - $boxHeight - $margin],
            AiTagOptions::POSITION_BOTTOM_RIGHT => [$width - $boxWidth - $margin, $height - $boxHeight - $margin],
            default => [$width - $boxWidth - $margin, $margin],
        };

        $x = max(0, $x);
        $y = max(0, $y);

        $image->draw()->rectangle(
            new Point($x, $y),
            new Point(min($width - 1, $x + $boxWidth), min($height - 1, $y + $boxHeight)),
            $boxColor,
            true,
        );

        $image->draw()->text($tag->text, $fontObject, new Point($x + $padding, $y + $padding));

        return true;
    }

    private function fontSize(int $width): int
    {
        return max($this->minFontSize, (int) round($width * $this->relativeFontSize));
    }

    /**
     * @param array<string, mixed>        $imagineOptions
     * @param array<string, array<mixed>> $preserveCopyright
     */
    private function save(ImagineImageInterface $image, string $path, AiTagOptions $tag, array $imagineOptions, array $preserveCopyright): void
    {
        $metadata = $this->readMetadata($path);
        $saveOptions = $this->saveOptions($path, $tag, $imagineOptions);

        $directory = \dirname($path);
        $temporary = $this->filesystem->tempnam($directory, 'aitag');
        $withMetadata = $this->filesystem->tempnam($directory, 'aitag');
        $this->filesystem->chmod([$temporary, $withMetadata], 0666, umask());

        try {
            $image->save($temporary, $saveOptions);

            if ($preserveCopyright && $metadata->getAll()) {
                try {
                    $this->metadataReaderWriter->applyCopyrightToFile($temporary, $withMetadata, $metadata, $preserveCopyright);
                } catch (\Throwable) {
                    $this->filesystem->rename($temporary, $withMetadata, true);
                }
            } else {
                $this->filesystem->rename($temporary, $withMetadata, true);
            }

            $this->filesystem->rename($withMetadata, $path, true);
        } finally {
            $this->filesystem->remove([$temporary, $withMetadata]);
        }
    }

    /**
     * @param array<string, mixed> $imagineOptions
     *
     * @return array<string, mixed>
     */
    private function saveOptions(string $path, AiTagOptions $tag, array $imagineOptions): array
    {
        $options = $imagineOptions;
        unset($options[AiTagOptions::OPTION_KEY]);

        foreach (self::QUALITY_KEYS as $key) {
            unset($options[$key]);
        }

        $format = $options['format'] ?? strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $options['format'] = $format;
        $options['quality'] = $tag->quality;
        $options[$format.'_quality'] = $tag->quality;

        return $options;
    }

    private function readMetadata(string $path): ImageMetadata
    {
        try {
            return $this->metadataReaderWriter->parse($path);
        } catch (\Throwable) {
            return new ImageMetadata([]);
        }
    }

    private function averageLuminance(ImagineImageInterface $image, string $corner): int
    {
        $width = $image->getSize()->getWidth();
        $height = $image->getSize()->getHeight();

        $startX = str_contains($corner, 'right') ? (int) ($width * 0.6) : 0;
        $startY = str_contains($corner, 'bottom') ? (int) ($height * 0.82) : 0;
        $endX = min($width, $startX + max(1, (int) ($width * 0.4)));
        $endY = min($height, $startY + max(1, (int) ($height * 0.18)));

        $step = max(1, (int) round($width / 120));
        $sum = 0;
        $count = 0;

        for ($x = $startX; $x < $endX; $x += $step) {
            for ($y = $startY; $y < $endY; $y += $step) {
                try {
                    $color = $image->getColorAt(new Point($x, $y));
                } catch (\Throwable) {
                    continue;
                }

                $sum += (int) (
                    0.2126 * $color->getValue(ColorInterface::COLOR_RED)
                    + 0.7152 * $color->getValue(ColorInterface::COLOR_GREEN)
                    + 0.0722 * $color->getValue(ColorInterface::COLOR_BLUE)
                );
                ++$count;
            }
        }

        return $count > 0 ? (int) ($sum / $count) : 128;
    }
}
