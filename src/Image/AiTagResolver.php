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

    private const MODIFIED_TRANSLATION_KEY = 'netzhirsch_ai_tag.label_modified';

    private const SHORT_TRANSLATION_KEY = 'netzhirsch_ai_tag.label_short';

    private const TRANSLATION_DOMAIN = 'netzhirsch_ai_tag';

    /**
     * @var array<string, array{position: string, text: string, kind: string}|false>
     */
    private array $cache = [];

    /**
     * @param list<string> $excludedPaths Pfade, die nie gekennzeichnet werden - auch
     *                                    dann nicht, wenn ein uebergeordneter Ordner
     *                                    markiert ist
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly PageFinder $pageFinder,
        private readonly TranslatorInterface $translator,
        private readonly CornerSelector $cornerSelector,
        private readonly TagStyle $style,
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly array $excludedPaths = [],
        private readonly LoggerInterface|null $logger = null,
        /**
         * Ob bei zu kleinem Bild auf die Kurzform ("KI") zurueckgegriffen werden darf,
         * statt das Label wegzulassen.
         */
        private readonly bool $shortTextFallback = true,
    ) {
    }

    /**
     * Bewusst ohne Lizenzpruefung: hier haengt auch die barrierefreie Textalternative
     * dran (Twig-Funktion netzhirsch_ai_tag_hint). Lizenzpflichtig ist allein das
     * Einbrennen, und das entscheidet der AiTagResizer.
     */
    public function resolve(ImageInterface $image, int $quality): AiTagOptions|null
    {
        $record = $this->findMarkedRecord($image->getPath());

        if (null === $record) {
            return null;
        }

        $locale = $this->resolveLocale();
        $text = '' !== $record['text'] ? $record['text'] : $this->resolveText($locale, $record['kind']);

        if ('' === $text) {
            return null;
        }

        $corner = AiTagOptions::POSITION_AUTO === $record['position']
            ? $this->cornerSelector->select($image->getImportantPart())
            : $record['position'];

        return new AiTagOptions($locale, $corner, $text, $quality, $this->style->fingerprint(), $this->shortText($locale));
    }

    public function isTaggableFormat(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TAGGABLE_EXTENSIONS, true);
    }

    /**
     * Die gesetzliche Standardformulierung der angeforderten Sprache. Wird auch im
     * Backend fuer die Lesbarkeitspruefung benutzt, wo es keinen Seitenkontext gibt.
     */
    public function defaultText(string|null $locale = null, string $kind = AiTagOptions::KIND_GENERATED): string
    {
        $locale ??= $this->resolveLocale();
        $key = AiTagOptions::KIND_MODIFIED === $kind ? self::MODIFIED_TRANSLATION_KEY : self::FALLBACK_TRANSLATION_KEY;
        $text = $this->translator->trans($key, [], self::TRANSLATION_DOMAIN, $locale);

        return $key === $text ? '' : trim($text);
    }

    /**
     * Wortlaut, Kurzform und Art, die fuer diese Datei gelten - auch wenn sie noch
     * nicht markiert ist (dann der Standard "generiert"). Grundlage der Ampel und der
     * Hinweise im Backend, die wissen muessen, was in welcher Groesse ins Bild kaeme.
     *
     * @return array{text: string, short: string, kind: string}
     */
    public function texts(string $absolutePath): array
    {
        $record = $this->findMarkedRecord($absolutePath);
        $kind = $record['kind'] ?? AiTagOptions::KIND_GENERATED;
        $locale = $this->resolveLocale();
        $text = '' !== ($record['text'] ?? '') ? $record['text'] : $this->resolveText($locale, $kind);

        return ['text' => $text, 'short' => $this->shortText($locale), 'kind' => $kind];
    }

    /**
     * Die Kurzform der Kennzeichnung ("KI" / "AI"), auf die zurueckgegriffen wird,
     * wenn der volle Wortlaut auch in der kleinsten Schriftgroesse nicht ins Bild
     * passt. Leer, wenn der Rueckgriff abgeschaltet ist (short_text_fallback) oder es
     * fuer die Sprache keine Kurzform gibt.
     *
     * Die Kommission nennt "AI" ausdruecklich als einzige Abkuerzung, die ein Label
     * verwenden soll; deshalb gibt es genau diese eine Kurzform und keine frei waehlbare.
     */
    public function shortText(string|null $locale = null): string
    {
        if (!$this->shortTextFallback) {
            return '';
        }

        $locale ??= $this->resolveLocale();
        $short = $this->translator->trans(self::SHORT_TRANSLATION_KEY, [], self::TRANSLATION_DOMAIN, $locale);

        return self::SHORT_TRANSLATION_KEY === $short ? '' : trim($short);
    }

    /**
     * Sucht den naechstliegenden als KI markierten Datensatz: zuerst die Datei
     * selbst, dann - fuer die Ordner-Vererbung - die uebergeordneten Ordner.
     *
     * @return array{position: string, text: string, kind: string}|null
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
            $row = $this->fetchMarkedRow($paths);
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
            'kind' => AiTagOptions::normaliseKind($row['netzhirschAiKind'] ?? null),
        ];
    }

    /**
     * Fragt den naechstliegenden markierten Eintrag ab. Faellt auf eine Abfrage ohne
     * die Spalte der Art zurueck, wenn es sie noch nicht gibt: nach einem Update des
     * Bundles fehlt sie, bis die Datenbank aktualisiert ist (contao:migrate). Ohne
     * den Rueckfall wuerde die Abfrage scheitern, und JEDES Bild verlore bis dahin
     * seine Kennzeichnung - und der Alt-Text seinen Hinweis. Dann gilt eben
     * "generiert", wie vor der Einfuehrung der Art.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed>|false
     */
    private function fetchMarkedRow(array $paths): array|false
    {
        try {
            return $this->connection->fetchAssociative(
                "SELECT netzhirschAiTagPosition, netzhirschAiTagText, netzhirschAiKind
                 FROM tl_files
                 WHERE path IN (?) AND netzhirschAiGenerated = '1'
                 ORDER BY CHAR_LENGTH(path) DESC",
                [$paths],
                [ArrayParameterType::STRING],
            );
        } catch (DbalException $exception) {
            $this->logger?->debug('KI-Kennzeichnung: Spalte netzhirschAiKind fehlt, Datenbank noch nicht aktualisiert: '.$exception->getMessage());

            return $this->connection->fetchAssociative(
                "SELECT netzhirschAiTagPosition, netzhirschAiTagText
                 FROM tl_files
                 WHERE path IN (?) AND netzhirschAiGenerated = '1'
                 ORDER BY CHAR_LENGTH(path) DESC",
                [$paths],
                [ArrayParameterType::STRING],
            );
        }
    }

    private function relativePath(string $absolutePath): string|null
    {
        $path = Path::makeRelative(Path::canonicalize($absolutePath), $this->projectDir);

        if (str_starts_with($path, '..') || !Path::isBasePath($this->uploadPath, $path)) {
            // Nicht im Dateiverwaltungs-Verzeichnis (z. B. bereits ein Cache-Bild)
            return null;
        }

        foreach ($this->excludedPaths as $excluded) {
            if (Path::isBasePath(Path::canonicalize($excluded), $path)) {
                return null;
            }
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

    private function resolveText(string $locale, string $kind = AiTagOptions::KIND_GENERATED): string
    {
        $request = $this->requestStack->getCurrentRequest();

        $rootPage = null !== $request ? $this->pageFinder->findRootPageForRequest($request) : null;

        if (null !== $rootPage) {
            // Ueber row() statt der magischen Property: das eigene Feld ist in den
            // @property-Annotationen von PageModel naturgemaess nicht deklariert.
            $field = AiTagOptions::KIND_MODIFIED === $kind ? 'netzhirschAiTagTextModified' : 'netzhirschAiTagText';
            $configured = trim((string) ($rootPage->row()[$field] ?? ''));

            if ('' !== $configured) {
                return $configured;
            }
        }

        return $this->defaultText($locale, $kind);
    }
}
