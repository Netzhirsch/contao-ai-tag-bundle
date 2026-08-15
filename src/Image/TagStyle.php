<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

/**
 * Das Aussehen der Kennzeichnung, gebuendelt an einer Stelle.
 *
 * Wichtig ist der Fingerabdruck: jede dieser Einstellungen veraendert das
 * erzeugte Bild, deshalb geht er in die eingefrorene Imagine-Option und damit in
 * Contaos Cache-Schluessel ein. Ohne ihn behielten bereits erzeugte Bilder nach
 * einer Design-Aenderung ihr altes Aussehen.
 */
final class TagStyle
{
    public const STYLE_BOX = 'box';

    public const STYLE_OUTLINE = 'outline';

    public const STYLE_PLAIN = 'plain';

    public const STYLES = [self::STYLE_BOX, self::STYLE_OUTLINE, self::STYLE_PLAIN];

    private string|null $fingerprint = null;

    public function __construct(
        public readonly string $style = self::STYLE_BOX,
        public readonly string|null $textColor = null,
        public readonly string|null $boxColor = null,
        public readonly int $boxOpacity = 60,
        public readonly float $cornerRadius = 0.25,
        public readonly float $paddingRatio = 0.45,
        public readonly float $marginRatio = 0.5,
        public readonly bool $uppercase = false,
        public readonly int $minFontSize = 11,
        public readonly float $relativeFontSize = 0.03,
        public readonly int $maxFontSize = 48,
        public readonly float $maxBoxWidth = 0.65,
        public readonly float $maxBoxHeight = 0.3,
        public readonly int $minWidth = 0,
        public readonly int $minHeight = 0,
    ) {
    }

    /**
     * Wunschgroesse relativ zur Bildbreite, aber nie unter der Lesbarkeitsgrenze und
     * nie so gross, dass das Label auf grossen Bildern zum Bildelement wird.
     */
    public function fontSize(int $imageWidth): int
    {
        return max($this->minFontSize, min($this->maxFontSize, (int) round($imageWidth * $this->relativeFontSize)));
    }

    public function isTooSmall(int $width, int $height): bool
    {
        return $width < $this->minWidth || $height < $this->minHeight;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint ??= substr(
            sha1(
                implode(
                    '|',
                    [
                        $this->style,
                        $this->textColor ?? 'auto',
                        $this->boxColor ?? 'auto',
                        $this->boxOpacity,
                        $this->cornerRadius,
                        $this->paddingRatio,
                        $this->marginRatio,
                        $this->uppercase ? '1' : '0',
                        $this->minFontSize,
                        $this->relativeFontSize,
                        $this->maxFontSize,
                        $this->maxBoxWidth,
                        $this->maxBoxHeight,
                        $this->minWidth,
                        $this->minHeight,
                    ],
                ),
            ),
            0,
            8,
        );
    }
}
