<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\License;

use Composer\InstalledVersions;

/**
 * Entscheidet, ob das Backend auf eine neuere Fassung hinweisen soll.
 *
 * Die Angaben stammen aus der letzten Antwort des Lizenzservers und liegen im
 * LicenseStore, damit der Hinweis auch dann steht, wenn gerade kein Aufruf
 * stattfindet - der Cron laeuft hoechstens alle sechs Stunden.
 *
 * Der Hinweis informiert und sonst nichts: er aktualisiert nicht selbst, er
 * blockiert nicht, und er beruehrt die Lizenzpruefung an keiner Stelle. Fehlen
 * die Angaben oder sind sie unsinnig, entsteht schlicht kein Hinweis.
 */
final class UpdateNotice
{
    public const PACKAGE = 'netzhirsch/contao-ai-tag-bundle';

    /**
     * @return array{version: string, url: string, security: bool}|null null, wenn nichts
     *                                                                  anzuzeigen ist
     */
    public static function fromStore(LicenseStore $store): array|null
    {
        return self::compare(
            $store->getLatestVersion(),
            $store->getReleaseNotesUrl(),
            $store->isSecurityRelease(),
            self::installedVersion(),
        );
    }

    /**
     * Getrennt von fromStore(), damit der Vergleich ohne Composer-Metadaten und ohne
     * Dateizugriff pruefbar ist.
     *
     * @return array{version: string, url: string, security: bool}|null
     */
    public static function compare(string $latest, string $url, bool $security, string $installed): array|null
    {
        $latest = trim($latest);

        if ('' === $latest || self::isDevVersion($installed)) {
            return null;
        }

        // version_compare statt Stringvergleich, sonst gilt 1.0.9 > 1.0.10. Das v faellt
        // auf beiden Seiten weg, weil Composer je nach Tag 1.0.10 oder v1.0.10 liefert.
        if (version_compare(ltrim($latest, 'vV'), ltrim($installed, 'vV'), '<=')) {
            return null;
        }

        return [
            'version' => $latest,
            'url' => str_starts_with($url, 'https://') ? $url : '',
            'security' => $security,
        ];
    }

    /**
     * Die installierte Fassung, wie Composer sie kennt ('' = nicht ermittelbar).
     * Oeffentlich, weil die Konsolenausgabe sie neben der angekuendigten nennt.
     */
    public static function installedVersion(): string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return '';
        }

        return (string) InstalledVersions::getPrettyVersion(self::PACKAGE);
    }

    /**
     * Auf einer Entwicklungsinstallation liefert version_compare Unsinn (dev-main
     * gegen 1.1.0), und ein Hinweis waere dort ohnehin daneben - wer aus dem Branch
     * installiert, hat den Stand selbst gewaehlt.
     */
    private static function isDevVersion(string $version): bool
    {
        $version = trim($version);

        return '' === $version
            || 'dev' === $version
            || str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev');
    }
}
