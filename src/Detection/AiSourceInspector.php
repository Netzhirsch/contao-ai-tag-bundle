<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Detection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\Audit\AuditActor;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Psr\Log\LoggerInterface;

/**
 * Prueft eine Datei auf ihre Herkunftsangaben und haelt das Ergebnis fest.
 *
 * Die Spalte netzhirschAiDetected kennt drei Zustaende: leer = noch nie geprueft,
 * "-" = geprueft, nichts gefunden, sonst das Signal. Damit wird keine Datei
 * doppelt gelesen, und die Uebersicht kann spaeter zeigen, was noch aussteht.
 */
final class AiSourceInspector
{
    public const MODE_OFF = 'off';

    public const MODE_SUGGEST = 'suggest';

    public const MODE_AUTO = 'auto';

    public const MODES = [self::MODE_OFF, self::MODE_SUGGEST, self::MODE_AUTO];

    private const CHECKED_WITHOUT_FINDING = '-';

    public function __construct(
        private readonly Connection $connection,
        private readonly AiSourceDetector $detector,
        private readonly AiTagResolver $resolver,
        private readonly AiTagAuditLogger $auditLogger,
        private readonly string $projectDir,
        private readonly string $mode = self::MODE_SUGGEST,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return self::MODE_OFF !== $this->mode;
    }

    /**
     * @param string $path Pfad relativ zum Projektverzeichnis, etwa files/bild.jpg
     */
    public function inspect(string $path): AiSourceSignal|null
    {
        if (!$this->isEnabled() || !$this->resolver->isTaggableFormat($path)) {
            return null;
        }

        $signal = $this->detector->detect($this->projectDir.'/'.$path);

        if (!$this->store($path, $signal)) {
            return $signal;
        }

        if (null === $signal) {
            return null;
        }

        $this->logger?->info(\sprintf('"%s" weist sich selbst als KI-generiert aus (%s).', $path, $signal->toStorage()));

        // Im Vorschlagsmodus entscheidet die Redaktion: ob gekennzeichnet werden muss,
        // haengt vom Inhalt ab (Deepfake ja, Illustration nein).
        if (self::MODE_AUTO === $this->mode && $signal->isDeclared()) {
            $this->flag($path, $signal);
        }

        return $signal;
    }

    /**
     * Sicherheitsnetz fuer Dateien, die an der Erkennung vorbeigekommen sind - in
     * Contao 5.3 gibt es kein Ereignis fuer neu registrierte Dateien, dort greift nur
     * der Upload im Backend.
     */
    public function inspectIfUnchecked(string $path): AiSourceSignal|null
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $stored = $this->connection->fetchOne('SELECT netzhirschAiDetected FROM tl_files WHERE path = ?', [$path]);
        } catch (DbalException) {
            return null;
        }

        if (false !== $stored && '' !== (string) $stored) {
            return AiSourceSignal::fromStorage((string) $stored);
        }

        return $this->inspect($path);
    }

    private function store(string $path, AiSourceSignal|null $signal): bool
    {
        try {
            $this->connection->update(
                'tl_files',
                ['netzhirschAiDetected' => $signal?->toStorage() ?? self::CHECKED_WITHOUT_FINDING],
                ['path' => $path],
            );

            return true;
        } catch (DbalException $exception) {
            $this->logger?->error('Erkennungsergebnis konnte nicht gespeichert werden: '.$exception->getMessage());

            return false;
        }
    }

    private function flag(string $path, AiSourceSignal $signal): void
    {
        try {
            $this->connection->update(
                'tl_files',
                ['netzhirschAiGenerated' => '1', 'tstamp' => time()],
                ['path' => $path],
            );
        } catch (DbalException $exception) {
            $this->logger?->error('Kennzeichnung konnte nicht automatisch gesetzt werden: '.$exception->getMessage());

            return;
        }

        $this->auditLogger->log(
            AiTagAuditLogger::ACTION_FLAG_SET,
            $path,
            false,
            $signal->toStorage(),
            new AuditActor(0, 'detection'),
        );
    }
}
