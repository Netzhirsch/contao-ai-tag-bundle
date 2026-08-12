<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Psr\Log\LoggerInterface;

/**
 * Ermittelt die TrueType-Datei fuer das Overlay.
 *
 * Das Bundle liefert bewusst keine Schrift mit (Lizenzfragen bei Webfonts),
 * sondern nimmt die konfigurierte Datei und faellt auf gaengige System-Schriften
 * zurueck. Ist keine Schrift auffindbar, wird NICHT gezeichnet und einmalig
 * gewarnt - ein fehlendes Overlay ist ein Compliance-Problem, ein Fehler 500
 * waere ein Ausfall.
 */
final class FontLocator
{
    private const CANDIDATES = [
        // Linux (Debian/Ubuntu, Alpine mit ttf-dejavu)
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        // macOS
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/Library/Fonts/Arial.ttf',
        // Windows
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
    ];

    private string|false|null $resolved = null;

    public function __construct(
        private readonly LoggerInterface|null $logger = null,
        private readonly string|null $configuredPath = null,
    ) {
    }

    public function locate(): string|null
    {
        if (null !== $this->resolved) {
            return false === $this->resolved ? null : $this->resolved;
        }

        if (null !== $this->configuredPath && '' !== $this->configuredPath) {
            if (is_file($this->configuredPath) && is_readable($this->configuredPath)) {
                return $this->resolved = $this->configuredPath;
            }

            $this->logger?->error(\sprintf('Die konfigurierte Schrift "%s" fuer die KI-Kennzeichnung ist nicht lesbar.', $this->configuredPath));
            $this->resolved = false;

            return null;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $this->resolved = $candidate;
            }
        }

        $this->logger?->error('Fuer die KI-Kennzeichnung wurde keine TrueType-Schrift gefunden. Bitte netzhirsch_contao_ai_tag.font_path setzen, sonst bleiben Bilder ungekennzeichnet.');
        $this->resolved = false;

        return null;
    }
}
