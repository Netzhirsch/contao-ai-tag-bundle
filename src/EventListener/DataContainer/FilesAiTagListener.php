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
use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Protokollierung und Redaktionshinweise fuer die KI-Kennzeichnung in tl_files.
 */
class FilesAiTagListener
{
    private const FLASH_INFO = 'contao.BE.info';

    private const FLASH_ERROR = 'contao.BE.error';

    /**
     * Pfade, fuer die in dieser Anfrage schon ein Eintrag "gesetzt" geschrieben
     * wurde. Contao speichert die Felder nacheinander; die Art, die im selben
     * Formular mitkommt, steht dann bereits in diesem Eintrag und soll nicht als
     * eigene Aenderung doppelt im Protokoll stehen.
     *
     * @var array<string, true>
     */
    private array $flagLogged = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly AiTagAuditLogger $auditLogger,
        private readonly AiTagResolver $resolver,
        private readonly TagRenderer $renderer,
        private readonly LicenseGate $gate,
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

        // Protokolliert wird nur eine echte Aenderung - ein erneutes Speichern ist kein
        // Vorgang, den der Nachweis festhalten soll.
        if ($isEnabled !== $this->currentFlag($path)) {
            $this->auditLogger->log(
                $isEnabled ? AiTagAuditLogger::ACTION_FLAG_SET : AiTagAuditLogger::ACTION_FLAG_UNSET,
                $path,
                $this->isFolder($path),
                null,
                null,
                // Beim Setzen die Art, die mit diesem Formular kommt - sonst stuende im Nachweis
                // nicht, ob "generiert" oder "bearbeitet" gekennzeichnet wurde.
                $isEnabled ? $this->submittedKind($path) : '',
            );
            $this->flagLogged[$path] = true;
        }

        // Die Warnungen dagegen bei JEDEM Speichern einer markierten Datei. Frueher
        // kamen sie nur beim Umschalten: wer eine bereits markierte Datei noch einmal
        // speicherte (etwa nachdem er die Ursache vermutete), sah nichts und musste
        // annehmen, es sei alles in Ordnung - dabei wurde weiter nichts eingebrannt.
        if ($isEnabled) {
            $this->addLicenseWarning();
            $this->addFontWarning();
            $this->addCoverageHints($path);
        }

        return $value;
    }

    #[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiKind.save')]
    public function onSaveKind(mixed $value, DataContainer $dc): mixed
    {
        $path = (string) $dc->id;
        $new = AiTagOptions::normaliseKind($value);

        // Nur die Art einer bereits gesetzten Markierung kann sich "aendern". Beim
        // Setzen steht sie schon im Eintrag "gesetzt" (flagLogged).
        if (!isset($this->flagLogged[$path]) && $this->currentFlag($path) && $new !== $this->currentKind($path)) {
            $this->auditLogger->log(
                AiTagAuditLogger::ACTION_KIND_CHANGED,
                $path,
                $this->isFolder($path),
                null,
                null,
                $new,
            );
        }

        return $new;
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
     * Ohne aktive Lizenz wird nichts eingebrannt. Das darf nicht stillschweigend
     * passieren: wer hier die Kennzeichnung setzt, verlaesst sich darauf, dass sie im
     * Bild landet - fehlt sie, ist das ein rechtliches Risiko.
     */
    private function addLicenseWarning(): void
    {
        if ($this->gate->isActive()) {
            return;
        }

        $this->addFlash(self::FLASH_ERROR, 'netzhirsch_ai_tag.license.hint.inactive', []);
    }

    /**
     * Ohne TrueType-Schrift wird nichts eingebrannt, auch mit gueltiger Lizenz. Das
     * stand bisher nur im Log - die Redaktion sah ein markiertes Bild, das
     * ungekennzeichnet ausgeliefert wurde.
     */
    private function addFontWarning(): void
    {
        if ($this->renderer->canDraw()) {
            return;
        }

        $this->addFlash(self::FLASH_ERROR, 'netzhirsch_ai_tag.hint.font', []);
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
            $this->addFlash(self::FLASH_INFO, 'netzhirsch_ai_tag.hint.format', ['%file%' => basename($path)]);

            return;
        }

        // isLegible() liefert ohne Schrift immer false - das hiesse dann "zu klein",
        // obwohl das Bild gross genug ist. Die richtige Meldung gibt addFontWarning().
        if (!$this->renderer->canDraw()) {
            return;
        }

        $absolutePath = $this->projectDir.'/'.$path;
        $size = is_file($absolutePath) ? @getimagesize($absolutePath) : false;

        if (false === $size) {
            return;
        }

        // Wortlaut und Kurzform, die fuer diese Datei gelten wuerden - mit der Art und
        // dem eigenen Text der Datei, nicht nur dem Standard.
        ['text' => $text, 'short' => $short] = $this->resolver->texts($absolutePath);
        $fit = '' === $text ? TagRenderer::FIT_FULL : $this->renderer->fit($size[0], $size[1], $text, $short);

        if (TagRenderer::FIT_FULL === $fit) {
            return;
        }

        $this->addFlash(
            self::FLASH_INFO,
            TagRenderer::FIT_SHORT === $fit ? 'netzhirsch_ai_tag.hint.short' : 'netzhirsch_ai_tag.hint.size',
            [
                '%file%' => basename($path),
                '%width%' => (string) $size[0],
                '%height%' => (string) $size[1],
                '%short%' => $short,
            ],
        );
    }

    private function currentKind(string $path): string
    {
        return AiTagOptions::normaliseKind($this->currentValue($path, 'netzhirschAiKind'));
    }

    /**
     * Die Art, die mit dem Formular abgeschickt wurde - sonst die gespeicherte. Beim
     * Setzen der Markierung laeuft der Callback des Schalters vor dem der Art, die
     * gewaehlte Art steht also noch nicht in der Datenbank.
     */
    private function submittedKind(string $path): string
    {
        $submitted = $this->requestStack->getCurrentRequest()?->request->get('netzhirschAiKind');

        return AiTagOptions::normaliseKind(\is_string($submitted) && '' !== $submitted ? $submitted : $this->currentKind($path));
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
    private function addFlash(string $level, string $key, array $parameters): void
    {
        $session = $this->requestStack->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add(
            $level,
            $this->translator->trans($key, $parameters, 'netzhirsch_ai_tag'),
        );
    }
}
