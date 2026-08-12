<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Contao\Image\ImportantPart;

/**
 * Waehlt die Bildecke fuer die Kennzeichnung.
 *
 * Die Kennzeichnung muss am Bildrand sitzen, aber moeglichst nicht auf dem Motiv.
 * Grundlage ist der in Contao pro Datei pflegbare wichtige Bildbereich: gewaehlt
 * wird die Ecke mit der geringsten Ueberlappung, bei Gleichstand rechts oben -
 * diese Position nennt die Rechtsliteratur als Beispiel.
 */
final class CornerSelector
{
    /**
     * Anteil von Breite und Hoehe, den das Label ueberschlaegig belegt. Bewusst
     * grosszuegig geschaetzt: die exakte Groesse steht erst beim Zeichnen fest, hier
     * geht es nur um die Reihenfolge der Ecken.
     */
    private const PROBE_WIDTH = 0.4;

    private const PROBE_HEIGHT = 0.18;

    private const CANDIDATES = [
        AiTagOptions::POSITION_TOP_RIGHT => [0.6, 0.0],
        AiTagOptions::POSITION_BOTTOM_RIGHT => [0.6, 0.82],
        AiTagOptions::POSITION_BOTTOM_LEFT => [0.0, 0.82],
        AiTagOptions::POSITION_TOP_LEFT => [0.0, 0.0],
    ];

    public function select(ImportantPart $importantPart): string
    {
        if ($this->isFullFrame($importantPart)) {
            return AiTagOptions::POSITION_TOP_RIGHT;
        }

        $best = AiTagOptions::POSITION_TOP_RIGHT;
        $bestOverlap = PHP_FLOAT_MAX;

        foreach (self::CANDIDATES as $corner => [$x, $y]) {
            $overlap = $this->overlap($x, $y, $importantPart);

            if ($overlap < $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $corner;
            }
        }

        return $best;
    }

    private function isFullFrame(ImportantPart $part): bool
    {
        return 0.0 === $part->getX()
            && 0.0 === $part->getY()
            && 1.0 === $part->getWidth()
            && 1.0 === $part->getHeight();
    }

    private function overlap(float $x, float $y, ImportantPart $part): float
    {
        $width = max(0.0, min($x + self::PROBE_WIDTH, $part->getX() + $part->getWidth()) - max($x, $part->getX()));
        $height = max(0.0, min($y + self::PROBE_HEIGHT, $part->getY() + $part->getHeight()) - max($y, $part->getY()));

        return $width * $height;
    }
}
