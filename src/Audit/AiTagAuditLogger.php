<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Audit;

use Contao\BackendUser;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Schreibt das Kennzeichnungs-Protokoll an zwei Stellen.
 *
 * Eigene Tabelle als Nachweis: Contao raeumt tl_log ueber PurgeExpiredDataCron
 * nach der Systemeinstellung logPeriod ab (Standard 7 Tage) - ein Nachweis, der
 * sich nach einer Woche selbst loescht, ist keiner. Die eigene Tabelle hat
 * ausserdem filterbare Spalten und ist ueber die Oberflaeche nicht aenderbar.
 *
 * Zusaetzlich eine Zeile im Systemprotokoll, damit die Aenderung auch dort
 * auftaucht, wo Administratoren ohnehin nachsehen.
 */
final class AiTagAuditLogger
{
    public const ACTION_FLAG_SET = 'flag_set';

    public const ACTION_FLAG_UNSET = 'flag_unset';

    public const ACTION_TEXT_CHANGED = 'text_changed';

    public const ACTION_POSITION_CHANGED = 'position_changed';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface|null $logger = null,
        private readonly LoggerInterface|null $contaoLogger = null,
    ) {
    }

    /**
     * @param AuditActor|null $actor Abweichender Verursacher, etwa ein MCP-Aufrufer
     *                               ohne Backend-Login
     */
    public function log(string $action, string $path, bool $isFolder, string|null $detail = null, AuditActor|null $actor = null): void
    {
        $actor ??= $this->currentBackendActor();

        try {
            $this->connection->insert('tl_netzhirsch_ai_tag_log', [
                'tstamp' => time(),
                'action' => $action,
                'scope' => $isFolder ? 'folder' : 'file',
                'filePath' => $path,
                'detail' => $detail ?? '',
                'userId' => $actor->userId,
                'username' => $actor->username,
            ]);
        } catch (DbalException $exception) {
            // Ein fehlgeschlagenes Protokoll darf die Redaktion nicht blockieren, muss aber
            // sichtbar bleiben.
            $this->logger?->error('Kennzeichnungs-Protokoll konnte nicht geschrieben werden: '.$exception->getMessage());
        }

        $this->mirrorToSystemLog($action, $path, $actor);
    }

    private function currentBackendActor(): AuditActor
    {
        $user = $this->security->getUser();

        if (!$user instanceof BackendUser) {
            return new AuditActor(0, 'system');
        }

        return new AuditActor((int) $user->id, (string) $user->username);
    }

    private function mirrorToSystemLog(string $action, string $path, AuditActor $actor): void
    {
        $this->contaoLogger?->info(
            $this->translator->trans(
                'netzhirsch_ai_tag.log.'.$action,
                ['%path%' => $path],
                'netzhirsch_ai_tag',
            ),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::FILES, $actor->username, null, null, $actor->source)],
        );
    }
}
