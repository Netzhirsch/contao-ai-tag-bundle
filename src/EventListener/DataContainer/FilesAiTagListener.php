<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Protokollierung und Redaktionshinweise fuer die KI-Kennzeichnung in tl_files.
 */
class FilesAiTagListener
{
    private const FLASH_INFO = 'contao.BE.info';

    public function __construct(
        private readonly Connection $connection,
        private readonly AiTagAuditLogger $auditLogger,
        private readonly AiTagResolver $resolver,
        private readonly TagRenderer $renderer,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly string $projectDir,
    ) {
    }

    #[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiGenerated.save')]
    public function onSaveFlag(mixed $value, DataContainer $dc): mixed
    {
        $path = (string) $dc->id;
        $isEnabled = '1' === (string) $value;

        if ($isEnabled === $this->currentFlag($path)) {
            return $value;
        }

        $this->auditLogger->log(
            $isEnabled ? AiTagAuditLogger::ACTION_FLAG_SET : AiTagAuditLogger::ACTION_FLAG_UNSET,
            $path,
            $this->isFolder($path),
        );

        if ($isEnabled) {
            $this->addCoverageHints($path);
        }

        return $value;
    }

    #[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiTagText.save')]
    public function onSaveText(mixed $value, DataContainer $dc): mixed
    {
        $path = (string) $dc->id;
        $new = trim((string) $value);

        if ($new !== $this->currentValue($path, 'netzhirschAiTagText')) {
            $this->auditLogger->log(
                AiTagAuditLogger::ACTION_TEXT_CHANGED,
                $path,
                $this->isFolder($path),
                '' === $new ? null : $new,
            );
        }

        return $value;
    }

    #[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiTagPosition.save')]
    public function onSavePosition(mixed $value, DataContainer $dc): mixed
    {
        $path = (string) $dc->id;
        $new = (string) $value;

        if ($new !== $this->currentValue($path, 'netzhirschAiTagPosition')) {
            $this->auditLogger->log(
                AiTagAuditLogger::ACTION_POSITION_CHANGED,
                $path,
                $this->isFolder($path),
                $new,
            );
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    #[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiTagPosition.options')]
    public function getPositionOptions(): array
    {
        return AiTagOptions::POSITIONS;
    }

    /**
     * Sagt der Redaktion sofort, wenn die Kennzeichnung nicht ins Bild gebrannt
     * werden kann - dann traegt sie nur die Textalternative im Markup.
     */
    private function addCoverageHints(string $path): void
    {
        if ($this->isFolder($path)) {
            return;
        }

        if (!$this->resolver->isTaggableFormat($path)) {
            $this->addInfo('netzhirsch_ai_tag.hint.format', ['%file%' => basename($path)]);

            return;
        }

        $absolutePath = $this->projectDir.'/'.$path;
        $size = is_file($absolutePath) ? @getimagesize($absolutePath) : false;

        if (false === $size) {
            return;
        }

        $text = $this->currentValue($path, 'netzhirschAiTagText');
        $text = '' !== $text ? $text : $this->resolver->defaultText();

        if ('' === $text || $this->renderer->isLegible($size[0], $size[1], $text)) {
            return;
        }

        $this->addInfo('netzhirsch_ai_tag.hint.size', [
            '%file%' => basename($path),
            '%width%' => (string) $size[0],
            '%height%' => (string) $size[1],
        ]);
    }

    private function currentFlag(string $path): bool
    {
        return '1' === $this->currentValue($path, 'netzhirschAiGenerated');
    }

    private function currentValue(string $path, string $field): string
    {
        $value = $this->connection->fetchOne(
            \sprintf('SELECT %s FROM tl_files WHERE path = ?', $this->connection->quoteIdentifier($field)),
            [$path],
        );

        return false === $value || null === $value ? '' : trim((string) $value);
    }

    private function isFolder(string $path): bool
    {
        return is_dir($this->projectDir.'/'.$path);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function addInfo(string $key, array $parameters): void
    {
        $session = $this->requestStack->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add(
            self::FLASH_INFO,
            $this->translator->trans($key, $parameters, 'netzhirsch_ai_tag'),
        );
    }
}
