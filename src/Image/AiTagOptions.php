<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

/**
 * Die Kennzeichnungs-Konfiguration eines einzelnen Resize-Vorgangs.
 *
 * Diese Werte werden beim Aufbau des <picture>-Elements ermittelt und als eigene
 * Imagine-Option eingefroren, weil beim spaeteren (deferred) Erzeugen des Bildes
 * weder der Request (Sprache) noch der wichtige Bildbereich verfuegbar sind. Da
 * Contao die Imagine-Optionen in den Cache-Hash einrechnet, entsteht dadurch
 * automatisch eine eigene Bilddatei pro Sprache und Textfassung.
 */
final class AiTagOptions
{
    /**
     * Schluessel der Imagine-Option. GD und Imagick lesen Optionen ausschliesslich
     * per isset() auf bekannte Schluessel, unbekannte werden ignoriert.
     */
    public const OPTION_KEY = 'netzhirsch_ai_tag';

    public const POSITION_AUTO = 'auto';

    public const POSITION_TOP_RIGHT = 'top-right';

    public const POSITION_TOP_LEFT = 'top-left';

    public const POSITION_BOTTOM_RIGHT = 'bottom-right';

    public const POSITION_BOTTOM_LEFT = 'bottom-left';

    public const POSITIONS = [
        self::POSITION_AUTO,
        self::POSITION_TOP_RIGHT,
        self::POSITION_TOP_LEFT,
        self::POSITION_BOTTOM_RIGHT,
        self::POSITION_BOTTOM_LEFT,
    ];

    /**
     * Version des Nutzdaten-Formats. Eine Erhoehung invalidiert saemtliche bereits
     * erzeugten Bilder, weil sie in den Cache-Hash eingeht.
     */
    private const PAYLOAD_VERSION = 1;

    public function __construct(
        public readonly string $locale,
        public readonly string $corner,
        public readonly string $text,
        public readonly int $quality,
        public readonly string $styleFingerprint = '',
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'v' => self::PAYLOAD_VERSION,
            'locale' => $this->locale,
            'corner' => $this->corner,
            'text' => $this->text,
            'quality' => $this->quality,
            'style' => $this->styleFingerprint,
        ];
    }

    /**
     * @param array<string, mixed>|mixed $payload
     */
    public static function fromArray(mixed $payload): self|null
    {
        if (!\is_array($payload) || self::PAYLOAD_VERSION !== ($payload['v'] ?? null)) {
            return null;
        }

        if (!isset($payload['locale'], $payload['corner'], $payload['text'], $payload['quality'])) {
            return null;
        }

        return new self(
            (string) $payload['locale'],
            (string) $payload['corner'],
            (string) $payload['text'],
            (int) $payload['quality'],
            (string) ($payload['style'] ?? ''),
        );
    }
}
