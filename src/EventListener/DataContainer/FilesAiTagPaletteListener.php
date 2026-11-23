<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Blendet Art, Position, Text, Vorschau und Abdeckung ein, sobald ein Eintrag der
 * Dateiverwaltung als KI-Inhalt markiert ist.
 *
 * Absichtlich keine Unterpalette: die Dateiverwaltung (DC_Folder) wertet sie auf
 * keiner unterstuetzten Contao-Version aus. 5.3 kennt dort gar keine
 * Unterpaletten, ab 5.7 sucht der PaletteBuilder den Datensatz ueber die
 * numerische ID - die ID ist hier aber der Pfad, gefunden wird nichts. Auch der
 * Ajax-Umschalter antwortet fuer DC_Folder nur mit 204. Die Felder waren deshalb
 * im Formular nie zu sehen und darum auch nicht zu speichern.
 *
 * Der onpalette-Callback laeuft dagegen auf allen Versionen. Das Haekchen schickt
 * das Formular ab (submitOnChange), danach steht der neue Zustand in der Anfrage.
 */
#[AsCallback(table: 'tl_files', target: 'config.onpalette')]
class FilesAiTagPaletteListener
{
    public const FLAG = 'netzhirschAiGenerated';

    public const FIELDS = 'netzhirschAiKind,netzhirschAiTagPosition,netzhirschAiTagText,netzhirschAiTagPreview,netzhirschAiTagCoverage';

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(string $palette, DataContainer $dc): string
    {
        // Ohne den Schalter in der Palette (Feldrechte, fremde Anpassungen) gibt es
        // nichts anzuhaengen; stehen die Felder schon drin, nicht doppelt.
        if (!preg_match('/\b'.self::FLAG.'\b/', $palette) || str_contains($palette, 'netzhirschAiKind')) {
            return $palette;
        }

        if (!$this->isFlagged((string) $dc->id)) {
            return $palette;
        }

        return (string) preg_replace('/\b'.self::FLAG.'\b/', self::FLAG.','.self::FIELDS, $palette, 1);
    }

    private function isFlagged(string $path): bool
    {
        // Gerade abgeschickt (Haekchen gesetzt oder entfernt): das Formular gilt, nicht
        // die Datenbank. Contao schreibt fuer ein leeres Haekchen ein verstecktes Feld
        // mit "", der Schluessel ist also in beiden Faellen vorhanden.
        $request = $this->requestStack->getCurrentRequest();

        if ('tl_files' === $request?->request->get('FORM_SUBMIT') && $request->request->has(self::FLAG)) {
            return '1' === (string) $request->request->get(self::FLAG);
        }

        if ('' === $path) {
            return false;
        }

        return '1' === (string) $this->connection->fetchOne('SELECT netzhirschAiGenerated FROM tl_files WHERE path = ?', [$path]);
    }
}
