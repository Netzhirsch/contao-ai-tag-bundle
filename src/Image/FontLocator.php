<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Psr\Log\LoggerInterface;

/**
 * Ermittelt die TrueType-Datei fuer das Overlay.
 *
 * Das Bundle liefert DejaVu Sans mit (resources/fonts/, Lizenztext liegt
 * daneben). Das ist Absicht und keine Bequemlichkeit: eine Kennzeichnung, die vom
 * Zufall des Servers abhaengt, ist keine. Wer Systemschriften sucht, scheitert
 * auf Hostern mit open_basedir (PHP darf /usr/share/fonts nicht lesen) und auf
 * schlanken Containern ohne Schriftpaket - und genau dort fehlte das Label, ohne
 * dass es jemand merkte. Mit der mitgelieferten Schrift sieht das Label ausserdem
 * ueberall gleich aus, und die Lesbarkeitspruefung rechnet mit denselben Massen
 * wie das Einbrennen.
 *
 * Eine eigene Schrift ueberschreibt sie ueber font_path. Eine dort angegebene,
 * aber fehlende Datei ist ein Fehler und faellt NICHT stillschweigend auf die
 * mitgelieferte zurueck: der Betreiber wollte eine bestimmte Schrift, und ein
 * anderes Aussehen als gewaehlt waere eine unbemerkte Abweichung.
 *
 * Ist keine Schrift lesbar, wird NICHT gezeichnet und einmalig gewarnt - ein
 * fehlendes Overlay ist ein Compliance-Problem, ein Fehler 500 waere ein Ausfall.
 */
final class FontLocator
{
    /**
     * Die mitgelieferte Schrift. Unveraendert eingebunden: die Lizenz (Bitstream
     * Vera) verlangt bei Aenderungen eine Umbenennung, und sie darf nur als Teil
     * eines Softwarepakets verkauft werden - beides ist hier erfuellt.
     */
    public const BUNDLED_FONT = __DIR__.'/../../resources/fonts/DejaVuSans.ttf';

    private string|false|null $resolved = null;

    public function __construct(
        private readonly LoggerInterface|null $logger = null,
        private readonly string|null $configuredPath = null,
        /**
         * Nur fuer Tests: eine Installation, bei der die mitgelieferte Datei fehlt.
         */
        private readonly string $bundledPath = self::BUNDLED_FONT,
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

        if ($this->isUsable($this->bundledPath)) {
            // Ohne "src/Image/../../" im Pfad: so steht er lesbar in Log und Konsolenausgabe.
            return $this->resolved = @realpath($this->bundledPath) ?: $this->bundledPath;
        }

        // Nur bei einer beschaedigten Installation: die Datei gehoert zum Paket. Meist
        // hat ein Deployment resources/ ausgelassen oder die Rechte verbogen.
        $this->logger?->error(\sprintf(
            'Die mitgelieferte Schrift "%s" fuer die KI-Kennzeichnung ist nicht lesbar - das Paket ist unvollstaendig installiert. Bitte netzhirsch_contao_ai_tag.font_path auf eine .ttf-Datei setzen oder das Bundle neu installieren, sonst bleiben Bilder ungekennzeichnet.%s',
            $this->bundledPath,
            self::openBasedirHint((string) \ini_get('open_basedir')),
        ));
        $this->resolved = false;

        return null;
    }

    /**
     * Der Zusatz fuer die Fehlermeldung, wenn PHP mit open_basedir laeuft. Eine
     * konfigurierte Schrift ausserhalb der erlaubten Pfade (etwa unter
     * /usr/share/fonts) ist fuer PHP nicht lesbar, auch wenn sie existiert - das war
     * der Grund, warum das Bundle seine Schrift inzwischen selbst mitliefert.
     */
    public static function openBasedirHint(string $openBasedir): string
    {
        if ('' === trim($openBasedir)) {
            return '';
        }

        return ' PHP laeuft mit open_basedir - Dateien ausserhalb der erlaubten Pfade sind nicht lesbar.'
            .' Die .ttf-Datei in das Projektverzeichnis legen und font_path darauf setzen.';
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
