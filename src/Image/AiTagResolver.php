<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Image;

use Contao\CoreBundle\Routing\PageFinder;
use Contao\Image\ImageInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Entscheidet, ob eine Bilddatei als KI-generiert gekennzeichnet werden muss, und
 * loest Text, Sprache und Badge-Ecke auf.
 *
 * Textreihenfolge: Datei-Override -> Startpunkt der Website -> gesetzliche
 * Standardformulierung aus den Bundle-Uebersetzungen.
 */
final class AiTagResolver
{
    /**
     * Formate, die rasterbasiert gekennzeichnet werden koennen. SVG ist bewusst nicht
     * dabei: Vektorgrafiken werden von Contao nicht durch Imagine gerendert.
     */
    private const TAGGABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'heic', 'jxl'];

    private const FALLBACK_TRANSLATION_KEY = 'netzhirsch_ai_tag.label';

    private const TRANSLATION_DOMAIN = 'netzhirsch_ai_tag';

    /**
     * @var array<string, array{position: string, text: string}|false>
     */
    private array $cache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly PageFinder $pageFinder,
        private readonly TranslatorInterface $translator,
        private readonly CornerSelector $cornerSelector,
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function resolve(ImageInterface $image, int $quality): AiTagOptions|null
    {
        $record = $this->findMarkedRecord($image->getPath());

        if (null === $record) {
            return null;
        }

        $locale = $this->resolveLocale();
        $text = '' !== $record['text'] ? $record['text'] : $this->resolveText($locale);

        if ('' === $text) {
            return null;
        }

        $corner = AiTagOptions::POSITION_AUTO === $record['position']
            ? $this->cornerSelector->select($image->getImportantPart())
            : $record['position'];

        return new AiTagOptions($locale, $corner, $text, $quality);
    }

    public function isTaggableFormat(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TAGGABLE_EXTENSIONS, true);
    }

    /**
     * Die gesetzliche Standardformulierung der angeforderten Sprache. Wird auch im
     * Backend fuer die Lesbarkeitspruefung benutzt, wo es keinen Seitenkontext gibt.
     */
    public function defaultText(string|null $locale = null): string
    {
        $locale ??= $this->resolveLocale();
        $fallback = $this->translator->trans(self::FALLBACK_TRANSLATION_KEY, [], self::TRANSLATION_DOMAIN, $locale);

        return self::FALLBACK_TRANSLATION_KEY === $fallback ? '' : trim($fallback);
    }

    /**
     * Sucht den naechstliegenden als KI markierten Datensatz: zuerst die Datei
     * selbst, dann - fuer die Ordner-Vererbung - die uebergeordneten Ordner.
     *
     * @return array{position: string, text: string}|null
     */
    private function findMarkedRecord(string $absolutePath): array|null
    {
        if (!$this->isTaggableFormat($absolutePath)) {
            return null;
        }

        $relativePath = $this->relativePath($absolutePath);

        if (null === $relativePath) {
            return null;
        }

        if (isset($this->cache[$relativePath])) {
            return false === $this->cache[$relativePath] ? null : $this->cache[$relativePath];
        }

        $paths = $this->pathWithAncestors($relativePath);

        try {
            /** @var array{netzhirschAiTagPosition: string|null, netzhirschAiTagText: string|null}|false $row */
            $row = $this->connection->fetchAssociative(
                "SELECT netzhirschAiTagPosition, netzhirschAiTagText
                 FROM tl_files
                 WHERE path IN (?) AND netzhirschAiGenerated = '1'
                 ORDER BY CHAR_LENGTH(path) DESC",
                [$paths],
                [ArrayParameterType::STRING],
            );
        } catch (DbalException $exception) {
            // Kein Schema (Installation, CLI vor der Migration): nicht kennzeichnen, aber
            // auch nicht die Bildauslieferung sprengen.
            $this->logger?->debug('KI-Kennzeichnung konnte tl_files nicht abfragen: '.$exception->getMessage());

            return null;
        }

        if (false === $row) {
            $this->cache[$relativePath] = false;

            return null;
        }

        $position = (string) ($row['netzhirschAiTagPosition'] ?? '');

        return $this->cache[$relativePath] = [
            'position' => \in_array($position, AiTagOptions::POSITIONS, true) ? $position : AiTagOptions::POSITION_AUTO,
            'text' => trim((string) ($row['netzhirschAiTagText'] ?? '')),
        ];
    }

    private function relativePath(string $absolutePath): string|null
    {
        $path = Path::makeRelative(Path::canonicalize($absolutePath), $this->projectDir);

        if (str_starts_with($path, '..') || !Path::isBasePath($this->uploadPath, $path)) {
            // Nicht im Dateiverwaltungs-Verzeichnis (z. B. bereits ein Cache-Bild)
            return null;
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function pathWithAncestors(string $relativePath): array
    {
        $paths = [$relativePath];
        $current = $relativePath;

        while (($parent = \dirname($current)) !== $current && Path::isBasePath($this->uploadPath, $parent)) {
            $paths[] = $parent;
            $current = $parent;
        }

        return $paths;
    }

    private function resolveLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->getLocale() ?? $this->translator->getLocale();
    }

    private function resolveText(string $locale): string
    {
        $request = $this->requestStack->getCurrentRequest();

        $rootPage = null !== $request ? $this->pageFinder->findRootPageForRequest($request) : null;

        if (null !== $rootPage) {
            // Ueber row() statt der magischen Property: das eigene Feld ist in den
            // @property-Annotationen von PageModel naturgemaess nicht deklariert.
            $configured = trim((string) ($rootPage->row()['netzhirschAiTagText'] ?? ''));

            if ('' !== $configured) {
                return $configured;
            }
        }

        return $this->defaultText($locale);
    }
}
