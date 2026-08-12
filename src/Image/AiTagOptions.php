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
final readonly class AiTagOptions
{
    /**
     * Schluessel der Imagine-Option. GD und Imagick lesen Optionen ausschliesslich
     * per isset() auf bekannte Schluessel, unbekannte werden ignoriert.
     */
    public const string OPTION_KEY = 'netzhirsch_ai_tag';

    public const string POSITION_AUTO = 'auto';

    public const string POSITION_TOP_RIGHT = 'top-right';

    public const string POSITION_TOP_LEFT = 'top-left';

    public const string POSITION_BOTTOM_RIGHT = 'bottom-right';

    public const string POSITION_BOTTOM_LEFT = 'bottom-left';

    public const array POSITIONS = [
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
    private const int PAYLOAD_VERSION = 1;

    public function __construct(
        public string $locale,
        public string $corner,
        public string $text,
        public int $quality,
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
        );
    }
}
