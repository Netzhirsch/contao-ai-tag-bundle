<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Audit;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Schreibt das Kennzeichnungs-Protokoll.
 *
 * Eigene Tabelle statt tl_log, weil der Nachweis, wer wann welche Datei als
 * KI-generiert gekennzeichnet hat, Aufbewahrungscharakter hat und nicht mit der
 * allgemeinen Systemprotokollierung rotieren soll.
 */
final readonly class AiTagAuditLogger
{
    public const string ACTION_FLAG_SET = 'flag_set';

    public const string ACTION_FLAG_UNSET = 'flag_unset';

    public const string ACTION_TEXT_CHANGED = 'text_changed';

    public const string ACTION_POSITION_CHANGED = 'position_changed';

    public function __construct(
        private Connection $connection,
        private Security $security,
        private LoggerInterface|null $logger = null,
    ) {
    }

    public function log(string $action, string $path, bool $isFolder, string|null $detail = null): void
    {
        $user = $this->security->getUser();

        try {
            $this->connection->insert('tl_netzhirsch_ai_tag_log', [
                'tstamp' => time(),
                'action' => $action,
                'scope' => $isFolder ? 'folder' : 'file',
                'filePath' => $path,
                'detail' => $detail ?? '',
                'userId' => $user instanceof BackendUser ? (int) $user->id : 0,
                'username' => $user instanceof BackendUser ? (string) $user->username : 'system',
            ]);
        } catch (DbalException $exception) {
            // Ein fehlgeschlagenes Protokoll darf die Redaktion nicht blockieren, muss aber
            // sichtbar bleiben.
            $this->logger?->error('Kennzeichnungs-Protokoll konnte nicht geschrieben werden: '.$exception->getMessage());
        }
    }
}
