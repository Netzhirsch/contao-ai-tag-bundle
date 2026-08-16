<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Detection;

/**
 * Was eine Datei ueber ihre eigene Herkunft aussagt.
 *
 * Bewusst zwei Stufen: eine Datei, die den IPTC-Standard mitbringt, erklaert sich
 * selbst als KI-generiert; ein bekannter Programmname im CreatorTool ist nur ein
 * Indiz. Die Unterscheidung steht im Backend und im Protokoll, damit niemand ein
 * Indiz fuer einen Nachweis haelt.
 */
final class AiSourceSignal
{
    public const DECLARED = 'declared';

    public const HINT = 'hint';

    public function __construct(
        public readonly string $confidence,
        public readonly string $source,
        public readonly string $detail = '',
    ) {
    }

    public function isDeclared(): bool
    {
        return self::DECLARED === $this->confidence;
    }

    /**
     * Kompakte Fassung fuer die Datenbankspalte, etwa "declared:DigitalSourceType"
     * oder "hint:CreatorTool=Midjourney".
     */
    public function toStorage(): string
    {
        return $this->confidence.':'.$this->source.('' !== $this->detail ? '='.$this->detail : '');
    }

    public static function fromStorage(string $value): self|null
    {
        $value = trim($value);

        if ('' === $value || !str_contains($value, ':')) {
            return null;
        }

        [$confidence, $rest] = explode(':', $value, 2);

        if (!\in_array($confidence, [self::DECLARED, self::HINT], true)) {
            return null;
        }

        [$source, $detail] = explode('=', $rest, 2) + ['', ''];

        return new self($confidence, $source, $detail);
    }
}
