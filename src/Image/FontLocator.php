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
            if ($this->isUsable($this->configuredPath)) {
                return $this->resolved = $this->configuredPath;
            }

            $this->logger?->error(\sprintf(
                'Die konfigurierte Schrift "%s" fuer die KI-Kennzeichnung ist nicht lesbar.%s',
                $this->configuredPath,
                self::openBasedirHint((string) \ini_get('open_basedir')),
            ));
            $this->resolved = false;

            return null;
        }

        foreach (self::CANDIDATES as $candidate) {
            if ($this->isUsable($candidate)) {
                return $this->resolved = $candidate;
            }
        }

        $this->logger?->error(
            'Fuer die KI-Kennzeichnung wurde keine TrueType-Schrift gefunden. Bitte netzhirsch_contao_ai_tag.font_path setzen, sonst bleiben Bilder ungekennzeichnet.'
            .self::openBasedirHint((string) \ini_get('open_basedir')),
        );
        $this->resolved = false;

        return null;
    }

    /**
     * Der Zusatz fuer die Fehlermeldung, wenn PHP mit open_basedir laeuft. Auf Shared
     * Hosting ist das der haeufigste Grund, warum eine vorhandene Systemschrift nicht
     * gefunden wird: PHP darf /usr/share/fonts schlicht nicht lesen, und die Schrift
     * muss ins Projektverzeichnis kopiert werden.
     */
    public static function openBasedirHint(string $openBasedir): string
    {
        if ('' === trim($openBasedir)) {
            return '';
        }

        return ' PHP laeuft mit open_basedir - Systemschriften ausserhalb der erlaubten Pfade sind nicht lesbar.'
            .' Die .ttf-Datei in das Projektverzeichnis kopieren und font_path darauf setzen.';
    }

    /**
     * Mit Unterdrueckung der Warnung: unter open_basedir meldet is_file() fuer jeden
     * Pfad ausserhalb der erlaubten Verzeichnisse eine PHP-Warnung. Die
     * Kandidatenliste ist absichtlich lang; jeder Fehlschlag waere sonst ein eigener
     * Logeintrag - und die eigentliche Fehlermeldung ginge darin unter.
     */
    private function isUsable(string $path): bool
    {
        return @is_file($path) && @is_readable($path);
    }
}
