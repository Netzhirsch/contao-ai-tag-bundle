<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Contao\Image\Metadata\ImageMetadata;
use Contao\Image\Metadata\MetadataReaderWriter;
use Imagine\Image\AbstractFont;
use Imagine\Image\Box;
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
final class TagRenderer
{
    /**
     * Qualitaets-Schluessel, die vor dem zweiten Speichern zurueckgesetzt werden,
     * damit die Zielqualitaet der Bildgroesse und nicht die angehobene erste
     * Kodierung greift.
     */
    private const QUALITY_KEYS = [
        'quality',
        'jpeg_quality',
        'webp_quality',
        'avif_quality',
        'heic_quality',
        'jxl_quality',
        'png_compression_level',
    ];

    private const OPAQUE = 100;

    public function __construct(
        private readonly ImagineInterface $imagine,
        private readonly MetadataReaderWriter $metadataReaderWriter,
        private readonly FontLocator $fontLocator,
        private readonly Filesystem $filesystem,
        private readonly TagStyle $style,
        private readonly LoggerInterface|null $logger = null,
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

        $drawn = $this->draw($image, $tag, $font, $path);

        // Auch ohne Overlay neu speichern: die Datei traegt sonst die angehobene erste
        // Kodierung statt der Zielqualitaet der Bildgroesse.
        $this->save($image, $path, $tag, $imagineOptions, $preserveCopyright);

        return $drawn;
    }

    /**
     * Prueft ohne zu zeichnen, ob die Kennzeichnung in der angegebenen Groesse lesbar
     * waere. Grundlage fuer die Backend-Warnung bei zu kleinen Bildgroessen.
     */
    public function isLegible(int $width, int $height, string $text): bool
    {
        $font = $this->fontLocator->locate();

        if (null === $font || $this->style->isTooSmall($width, $height)) {
            return false;
        }

        $fontSize = $this->style->fontSize($width);
        $box = $this->imagine->font($font, $fontSize, (new RGB())->color('#000000', self::OPAQUE))->box($this->label($text));

        return $this->fits($box->getWidth(), $box->getHeight(), $fontSize, $width, $height);
    }

    private function draw(ImagineImageInterface $image, AiTagOptions $tag, string $font, string $path): bool
    {
        $width = $image->getSize()->getWidth();
        $height = $image->getSize()->getHeight();

        if ($this->style->isTooSmall($width, $height)) {
            $this->logger?->info(\sprintf('KI-Kennzeichnung fuer "%s" ausgelassen: %dx%d px liegt unter der konfigurierten Mindestgroesse.', $path, $width, $height));

            return false;
        }

        $text = $this->label($tag->text);
        $fontSize = $this->style->fontSize($width);
        $corner = AiTagOptions::POSITION_AUTO === $tag->corner ? AiTagOptions::POSITION_TOP_RIGHT : $tag->corner;

        $palette = $image->palette();
        $isDarkBackground = $this->averageLuminance($image, $corner) < 128;

        $textColor = $palette->color($this->style->textColor ?? ($isDarkBackground ? '#ffffff' : '#000000'), self::OPAQUE);
        $fontObject = $this->imagine->font($font, $fontSize, $textColor);

        // Imagines eigene Schnittstellen passen nicht zusammen: font() liefert ein
        // FontInterface, text() verlangt eine AbstractFont. Alle mitgelieferten Treiber
        // liefern AbstractFont - ein fremder Treiber koennte es nicht.
        if (!$fontObject instanceof AbstractFont) {
            $this->logger?->warning(\sprintf('Der Imagine-Treiber liefert eine Schrift vom Typ %s, mit der nicht gezeichnet werden kann.', $fontObject::class));

            return false;
        }

        $textBox = $fontObject->box($text);
        $padding = $this->padding($fontSize);

        if (!$this->fits($textBox->getWidth(), $textBox->getHeight(), $fontSize, $width, $height)) {
            $this->logger?->info(\sprintf(
                'KI-Kennzeichnung fuer "%s" ausgelassen: bei %dx%d px passt das Label nicht lesbar ins Bild.',
                $path,
                $width,
                $height,
            ));

            return false;
        }

        $boxWidth = $textBox->getWidth() + 2 * $padding;
        $boxHeight = $textBox->getHeight() + 2 * $padding;
        $margin = (int) round($fontSize * $this->style->marginRatio);

        [$x, $y] = match ($corner) {
            AiTagOptions::POSITION_TOP_LEFT => [$margin, $margin],
            AiTagOptions::POSITION_BOTTOM_LEFT => [$margin, $height - $boxHeight - $margin],
            AiTagOptions::POSITION_BOTTOM_RIGHT => [$width - $boxWidth - $margin, $height - $boxHeight - $margin],
            default => [$width - $boxWidth - $margin, $margin],
        };

        $x = max(0, $x);
        $y = max(0, $y);

        if (TagStyle::STYLE_BOX === $this->style->style) {
            $boxColor = $palette->color(
                $this->style->boxColor ?? ($isDarkBackground ? '#000000' : '#ffffff'),
                $this->style->boxOpacity,
            );

            $this->drawBox($image, $x, $y, min($width - 1, $x + $boxWidth), min($height - 1, $y + $boxHeight), $boxHeight, $boxColor);
        }

        if (TagStyle::STYLE_OUTLINE === $this->style->style) {
            $this->drawOutline($image, $text, $font, $fontSize, $x + $padding, $y + $padding, $isDarkBackground, $palette);
        }

        $image->draw()->text($text, $fontObject, new Point($x + $padding, $y + $padding));

        return true;
    }

    /**
     * Zeichnet die Label-Flaeche, bei Bedarf mit runden Ecken.
     *
     * Die Teilflaechen duerfen sich nicht ueberlappen: die Flaeche ist
     * halbtransparent, und uebereinander gezeichnete Teile wuerden an den Nahtstellen
     * doppelt aufgetragen und als dunklere Kanten sichtbar. Deshalb drei Rechtecke
     * ohne Ueberschneidung und vier Kreissegmente in den verbleibenden Ecken.
     */
    private function drawBox(ImagineImageInterface $image, int $x1, int $y1, int $x2, int $y2, int $boxHeight, ColorInterface $color): void
    {
        $drawer = $image->draw();
        $radius = (int) round($boxHeight * $this->style->cornerRadius);
        $radius = max(0, min($radius, (int) floor(min($x2 - $x1, $y2 - $y1) / 2)));

        if ($radius < 1) {
            $drawer->rectangle(new Point($x1, $y1), new Point($x2, $y2), $color, true);

            return;
        }

        $drawer->rectangle(new Point($x1, $y1 + $radius), new Point($x2, $y2 - $radius), $color, true);
        $drawer->rectangle(new Point($x1 + $radius, $y1), new Point($x2 - $radius, $y1 + $radius - 1), $color, true);
        $drawer->rectangle(new Point($x1 + $radius, $y2 - $radius + 1), new Point($x2 - $radius, $y2), $color, true);

        // Die Kreissegmente muessen exakt in den verbleibenden Ecken landen. Ein
        // gefuellter Bogen zeichnet bis zu seinem Mittelpunkt einschliesslich, deshalb
        // liegt der Mittelpunkt einen Pixel innerhalb und der Durchmesser ist ungerade -
        // sonst ueberlappt jede Ecke die Rechtecke um eine Reihe und diese Naht wird bei
        // halbtransparenter Flaeche als dunklere Linie sichtbar.
        $diameter = new Box(2 * $radius - 1, 2 * $radius - 1);
        $left = $x1 + $radius - 1;
        $right = $x2 - $radius + 1;
        $top = $y1 + $radius - 1;
        $bottom = $y2 - $radius + 1;

        foreach (
            [
                [$left, $top, 180, 270],
                [$right, $top, 270, 360],
                [$left, $bottom, 90, 180],
                [$right, $bottom, 0, 90],
            ] as [$centerX, $centerY, $start, $end]
        ) {
            $drawer->pieSlice(new Point($centerX, $centerY), $diameter, $start, $end, $color, true);
        }
    }

    /**
     * Kontur statt Flaeche: der Text wird in der Gegenfarbe leicht versetzt mehrfach
     * gezeichnet, darueber liegt spaeter der eigentliche Text.
     */
    private function drawOutline(ImagineImageInterface $image, string $text, string $font, int $fontSize, int $x, int $y, bool $isDarkBackground, mixed $palette): void
    {
        $outlineColor = $palette->color($this->style->boxColor ?? ($isDarkBackground ? '#000000' : '#ffffff'), self::OPAQUE);
        $outlineFont = $this->imagine->font($font, $fontSize, $outlineColor);

        if (!$outlineFont instanceof AbstractFont) {
            return;
        }

        $offset = max(1, (int) round($fontSize * 0.07));

        foreach ([[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]] as [$dx, $dy]) {
            $image->draw()->text($text, $outlineFont, new Point(max(0, $x + $dx * $offset), max(0, $y + $dy * $offset)));
        }
    }

    private function label(string $text): string
    {
        return $this->style->uppercase ? mb_strtoupper($text) : $text;
    }

    private function padding(int $fontSize): int
    {
        return TagStyle::STYLE_BOX === $this->style->style ? (int) round($fontSize * $this->style->paddingRatio) : 0;
    }

    private function fits(int $textWidth, int $textHeight, int $fontSize, int $imageWidth, int $imageHeight): bool
    {
        $padding = $this->padding($fontSize);

        return $textWidth + 2 * $padding <= $imageWidth * $this->style->maxBoxWidth
            && $textHeight + 2 * $padding <= $imageHeight * $this->style->maxBoxHeight;
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
