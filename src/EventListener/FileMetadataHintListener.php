<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener;

use Contao\CoreBundle\Event\FileMetadataEvent;
use Contao\CoreBundle\File\Metadata;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\Twig\AiTagExtension;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Setzt die Textfassung der Kennzeichnung in den Alt-Text jeder Abbildung - an
 * der Stelle, an der Contao die Metadaten einer Datei bestimmt, und damit
 * unabhaengig vom Template.
 *
 * Anlass: die Nachrichtenliste (und jedes andere Legacy-Template) bekommt den
 * Alt-Text ueber Figure::applyLegacyTemplateData() und gibt ihn selbst aus. An
 * der Komponente `@Contao/component/_figure.html.twig`, in die das Bundle den
 * Hinweis bisher einbaute, laeuft so ein Template nie vorbei - die Teaser einer
 * Uebersichtsseite blieben ohne Hinweis, obwohl dasselbe Bild im Artikel einen
 * hatte. Jedes Projekt-Template haette einzeln angefasst werden muessen.
 *
 * FileMetadataEvent wird in FigureBuilder fuer jede Abbildung ausgeloest, und
 * sowohl die Twig-Figure als auch die Legacy-Daten (`alt`, `picture.alt`) lesen
 * ihren Alt-Text aus genau diesen Metadaten. Der Hinweis steht damit ueberall, wo
 * ein Template den Alt-Text aus den Metadaten nimmt.
 *
 * Gleiche Regeln wie im Twig-Override (hint_placement, hint_separator), und
 * derselbe Schutz vor doppelter Ausgabe: steht der Hinweis schon am Ende des
 * Alt-Textes, wird er nicht noch einmal angehaengt.
 *
 * Bewusst ohne Lizenzpruefung: die Textalternative bleibt auch ohne Lizenz erhalten.
 */
#[AsEventListener]
class FileMetadataHintListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AiTagExtension $extension,
        private readonly string $projectDir,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(FileMetadataEvent $event): void
    {
        $metadata = $event->getMetadata();

        // Ohne UUID gibt es keine Datei aus der Dateiverwaltung, und nur dort lassen
        // sich Markierungen setzen.
        $uuid = $metadata?->getUuid();

        if (null === $metadata || null === $uuid) {
            return;
        }

        $config = $this->extension->getHintConfig();

        if (!\in_array($config['placement'], [AiTagExtension::PLACEMENT_ALT, AiTagExtension::PLACEMENT_BOTH], true)) {
            return;
        }

        try {
            $path = $this->connection->fetchOne('SELECT path FROM tl_files WHERE uuid = ?', [StringUtil::uuidToBin($uuid)]);

            if (!\is_string($path) || '' === $path) {
                return;
            }

            $hint = $this->extension->getHint($this->projectDir.'/'.$path);
        } catch (\Throwable $exception) {
            // Eine Abbildung darf an der Kennzeichnung nie scheitern - dann eben ohne Hinweis.
            $this->logger?->warning('Hinweis fuer die KI-Kennzeichnung konnte nicht bestimmt werden: '.$exception->getMessage());

            return;
        }

        if (null === $hint || '' === $hint) {
            return;
        }

        $alt = $metadata->getAlt();

        if ('' !== $alt && str_ends_with($alt, $hint)) {
            return;
        }

        $event->setMetadata($metadata
            ->with([
                Metadata::VALUE_ALT => '' !== $alt ? $alt.$config['separator'].$hint : $hint,
            ]));
    }
}
