<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Mcp;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\Audit\AuditActor;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Netzhirsch\ContaoMcpBundle\Extension\McpToolPermissionProviderInterface;
use PhpMcp\Server\Attributes\McpTool;
use Symfony\Component\Filesystem\Path;

/**
 * MCP-Werkzeuge fuer die KI-Kennzeichnung.
 *
 * Der Server wird von netzhirsch/contao-mcp-bundle gestellt; die Tools sind bis zur
 * Freigabe durch den Betreiber (extension_tools_enabled) nicht erreichbar. Fuer Dateien
 * gibt es im MCP-Bundle keinen FieldProvider-Anschluss (den nutzen nur
 * Page/News/Article/FAQ/Event), deshalb eigene Tools statt einer Felderweiterung.
 *
 * Rechte: die Deklaration unten oeffnet die Tools - wie die Datei-Tools des Cores
 * - fuer Benutzer mit Zugriff auf das Dateiverwaltungs-Modul. Der schreibende
 * Aufruf prueft zusaetzlich per ensureCan() die echte Backend-Parität; dabei
 * greifen der FilesAiTagVoter (Recht netzhirsch_ai_tagp) und die
 * Feldrechte-Pruefung, weil die Felder als 'exclude' deklariert sind.
 *
 * Geschrieben wird direkt per Doctrine und nicht ueber FilesModel: die eigenen Felder
 * stehen naturgemaess nicht in dessen @property-Annotationen, und Model::mergeRow()
 * markiert nichts als geaendert - save() wuerde still nichts schreiben. Pfad, Hash und
 * UUID bleiben unberuehrt, DBAFS muss also nicht mitspielen.
 */
final class AiTagTool extends AbstractMcpTool implements McpToolPermissionProviderInterface
{
    private const MAX_TEXT_LENGTH = 128;

    private const MAX_LIST_LIMIT = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly AiTagResolver $resolver,
        private readonly TagRenderer $renderer,
        private readonly AiTagAuditLogger $auditLogger,
        private readonly string $projectDir,
        private readonly string $uploadPath,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMcpToolPermissions(): array
    {
        return [
            'netzhirsch_ai_tag_get' => ['kind' => 'module', 'module' => 'files'],
            'netzhirsch_ai_tag_list' => ['kind' => 'module', 'module' => 'files'],
            'netzhirsch_ai_tag_set' => ['kind' => 'module', 'module' => 'files'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'netzhirsch_ai_tag_get',
        description: 'Reads the AI labelling state of a file or folder from tl_files — no writes. Returns the own flag, the inherited flag including the folder it comes from, the effective label text, and whether the label can actually be burnt into this format.',
    )]
    public function get(string $path): array
    {
        try {
            $dbafsPath = $this->normalisePath($path);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => 'invalid_path', 'message' => $exception->getMessage()];
        }

        $this->framework->initialize();

        $row = $this->loadRow($dbafsPath);

        if (null === $row) {
            return ['error' => 'not_found', 'message' => \sprintf('No tl_files entry for "%s".', $dbafsPath)];
        }

        $inherited = $this->findMarkedAncestor($dbafsPath);

        return [
            'path' => $dbafsPath,
            'type' => $row['type'],
            'ai_generated' => '1' === $row['netzhirschAiGenerated'],
            'inherited_from' => $inherited,
            'effective' => '1' === $row['netzhirschAiGenerated'] || null !== $inherited,
            'position' => $row['netzhirschAiTagPosition'],
            'text' => $row['netzhirschAiTagText'],
            'effective_text' => '' !== $row['netzhirschAiTagText'] ? $row['netzhirschAiTagText'] : $this->resolver->defaultText(),
            'taggable_format' => 'folder' === $row['type'] || $this->resolver->isTaggableFormat($dbafsPath),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'netzhirsch_ai_tag_list',
        description: 'Lists the files and folders marked as AI-generated in tl_files — no writes. Optionally restricted to a folder. Use it to audit which images carry a label.',
    )]
    public function list(string|null $folder = null, int $limit = 50): array
    {
        $this->framework->initialize();

        $limit = max(1, min(self::MAX_LIST_LIMIT, $limit));
        $sql = "SELECT path, type, netzhirschAiTagPosition, netzhirschAiTagText FROM tl_files WHERE netzhirschAiGenerated = '1'";
        $parameters = [];

        if (null !== $folder && '' !== trim($folder)) {
            try {
                $prefix = $this->normalisePath($folder);
            } catch (\InvalidArgumentException $exception) {
                return ['error' => 'invalid_path', 'message' => $exception->getMessage()];
            }

            $sql .= ' AND (path = ? OR path LIKE ?)';
            $parameters[] = $prefix;
            $parameters[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'/%';
        }

        $sql .= ' ORDER BY path LIMIT '.$limit;

        $rows = $this->connection->fetchAllAssociative($sql, $parameters);

        return [
            'total' => \count($rows),
            'limit' => $limit,
            'truncated' => \count($rows) === $limit,
            'entries' => array_map(
                static fn (array $row): array => [
                    'path' => (string) $row['path'],
                    'type' => (string) $row['type'],
                    'position' => (string) $row['netzhirschAiTagPosition'],
                    'text' => (string) $row['netzhirschAiTagText'],
                ],
                $rows,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'netzhirsch_ai_tag_set',
        description: 'Sets or removes the AI labelling of a file or folder in tl_files. On a folder it applies to every image inside. Removing a label is destructive and requires confirm_destructive=true, because the label may be legally required. Logged to the AI labelling log and tl_log.',
    )]
    public function set(string $path, bool $ai_generated, string|null $position = null, string|null $text = null, bool $confirm_destructive = false): array
    {
        // Entfernen kann eine Rechtspflicht verletzen: nur mit Bestaetigung.
        if (!$ai_generated && ($error = $this->requireConfirmation($confirm_destructive))) {
            return $error;
        }

        try {
            $dbafsPath = $this->normalisePath($path);
            $position = $this->normalisePosition($position);
            $text = $this->normaliseText($text);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => 'invalid_input', 'message' => $exception->getMessage()];
        }

        $this->framework->initialize();

        $row = $this->loadRow($dbafsPath);

        if (null === $row) {
            return ['error' => 'not_found', 'message' => \sprintf('No tl_files entry for "%s".', $dbafsPath)];
        }

        $fields = ['netzhirschAiGenerated' => $ai_generated ? '1' : ''];

        if (null !== $position) {
            $fields['netzhirschAiTagPosition'] = $position;
        }

        if (null !== $text) {
            $fields['netzhirschAiTagText'] = $text;
        }

        // Backend-Paritaet: Modulzugriff, Voter und Feldrechte wie im Formular.
        if ($denial = $this->permissionGuard()->ensureCan('tl_files', 'update', null, $fields)) {
            return $denial;
        }

        $wasEnabled = '1' === $row['netzhirschAiGenerated'];

        // Direkt per Doctrine, siehe Klassen-Docblock.
        $this->connection->update(
            'tl_files',
            [...$fields, 'tstamp' => time()],
            ['path' => $dbafsPath],
        );

        $this->writeAuditTrail($dbafsPath, 'folder' === $row['type'], $wasEnabled, $ai_generated, $fields, $row);

        return [
            'path' => $dbafsPath,
            'type' => $row['type'],
            'ai_generated' => $ai_generated,
            'position' => $fields['netzhirschAiTagPosition'] ?? $row['netzhirschAiTagPosition'],
            'text' => $fields['netzhirschAiTagText'] ?? $row['netzhirschAiTagText'],
            'warnings' => $ai_generated ? $this->coverageWarnings($dbafsPath, $row['type']) : [],
        ];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $row
     */
    private function writeAuditTrail(string $dbafsPath, bool $isFolder, bool $wasEnabled, bool $isEnabled, array $fields, array $row): void
    {
        $actor = new AuditActor(
            $this->resolveAuthorId(),
            $this->authorResolver()->getLogUsername(),
            $this->authorResolver()->getLogSource(),
        );

        if ($wasEnabled !== $isEnabled) {
            $this->auditLogger->log(
                $isEnabled ? AiTagAuditLogger::ACTION_FLAG_SET : AiTagAuditLogger::ACTION_FLAG_UNSET,
                $dbafsPath,
                $isFolder,
                null,
                $actor,
            );
        }

        if (isset($fields['netzhirschAiTagText']) && $fields['netzhirschAiTagText'] !== $row['netzhirschAiTagText']) {
            $this->auditLogger->log(
                AiTagAuditLogger::ACTION_TEXT_CHANGED,
                $dbafsPath,
                $isFolder,
                '' === $fields['netzhirschAiTagText'] ? null : $fields['netzhirschAiTagText'],
                $actor,
            );
        }

        if (isset($fields['netzhirschAiTagPosition']) && $fields['netzhirschAiTagPosition'] !== $row['netzhirschAiTagPosition']) {
            $this->auditLogger->log(
                AiTagAuditLogger::ACTION_POSITION_CHANGED,
                $dbafsPath,
                $isFolder,
                $fields['netzhirschAiTagPosition'],
                $actor,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function coverageWarnings(string $dbafsPath, string $type): array
    {
        if ('folder' === $type) {
            return [];
        }

        if (!$this->resolver->isTaggableFormat($dbafsPath)) {
            return ['format_not_taggable: the label cannot be burnt into this format (e.g. SVG); only the text alternative in the markup applies.'];
        }

        $absolute = Path::join($this->projectDir, $dbafsPath);
        $size = is_file($absolute) ? @getimagesize($absolute) : false;

        if (false === $size) {
            return [];
        }

        $text = $this->resolver->defaultText();

        if ('' !== $text && !$this->renderer->isLegible($size[0], $size[1], $text)) {
            return [\sprintf('image_too_small: at %dx%d px no legible label fits; only the text alternative in the markup applies.', $size[0], $size[1])];
        }

        return [];
    }

    /**
     * @return array{path: string, type: string, netzhirschAiGenerated: string, netzhirschAiTagPosition: string, netzhirschAiTagText: string}|null
     */
    private function loadRow(string $dbafsPath): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT path, type, netzhirschAiGenerated, netzhirschAiTagPosition, netzhirschAiTagText FROM tl_files WHERE path = ?',
            [$dbafsPath],
        );

        if (false === $row) {
            return null;
        }

        return [
            'path' => (string) $row['path'],
            'type' => (string) $row['type'],
            'netzhirschAiGenerated' => (string) $row['netzhirschAiGenerated'],
            'netzhirschAiTagPosition' => (string) $row['netzhirschAiTagPosition'],
            'netzhirschAiTagText' => (string) $row['netzhirschAiTagText'],
        ];
    }

    private function findMarkedAncestor(string $dbafsPath): string|null
    {
        $paths = [];
        $current = $dbafsPath;

        while (($parent = \dirname($current)) !== $current && Path::isBasePath($this->uploadPath, $parent)) {
            $paths[] = $parent;
            $current = $parent;
        }

        if ([] === $paths) {
            return null;
        }

        $marked = $this->connection->fetchOne(
            \sprintf(
                "SELECT path FROM tl_files WHERE netzhirschAiGenerated = '1' AND path IN (%s) ORDER BY CHAR_LENGTH(path) DESC",
                implode(',', array_fill(0, \count($paths), '?')),
            ),
            $paths,
        );

        return false === $marked || null === $marked ? null : (string) $marked;
    }

    /**
     * Nie ungeprueft aus MCP-Eingaben Pfade bilden: nur relative Pfade innerhalb des
     * Dateiverwaltungs-Verzeichnisses, ohne Rueckwaertsnavigation.
     */
    private function normalisePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ('' === $path) {
            throw new \InvalidArgumentException('path must not be empty.');
        }

        if (Path::isAbsolute($path)) {
            throw new \InvalidArgumentException('path must be relative to the project directory, e.g. "files/images/photo.jpg".');
        }

        $canonical = Path::canonicalize($path);

        if (str_starts_with($canonical, '..')) {
            throw new \InvalidArgumentException('path must not navigate outside the project directory.');
        }

        if (!Path::isBasePath($this->uploadPath, $canonical)) {
            throw new \InvalidArgumentException(\sprintf('path must be inside the upload directory "%s".', $this->uploadPath));
        }

        return $canonical;
    }

    private function normalisePosition(string|null $position): string|null
    {
        if (null === $position) {
            return null;
        }

        $position = trim($position);

        if (!\in_array($position, AiTagOptions::POSITIONS, true)) {
            throw new \InvalidArgumentException(\sprintf('position must be one of: %s.', implode(', ', AiTagOptions::POSITIONS)));
        }

        return $position;
    }

    private function normaliseText(string|null $text): string|null
    {
        if (null === $text) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('text must not exceed %d characters.', self::MAX_TEXT_LENGTH));
        }

        return $text;
    }
}
