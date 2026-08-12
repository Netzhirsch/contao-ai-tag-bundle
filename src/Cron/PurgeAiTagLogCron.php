<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;

/**
 * Raeumt alte Eintraege des Kennzeichnungs-Protokolls ab.
 *
 * Das Protokoll speichert Benutzernamen; unbegrenzte Aufbewahrung waere fuer die
 * Nachweispflicht nicht erforderlich. Standard sind drei Jahre - lang genug, um
 * eine Kennzeichnungsentscheidung noch belegen zu koennen, ohne personenbezogene
 * Daten dauerhaft vorzuhalten. 0 schaltet die Bereinigung ab.
 */
#[AsCronJob('daily')]
final class PurgeAiTagLogCron
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $retentionDays,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $threshold = time() - $this->retentionDays * 86400;

        try {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM tl_netzhirsch_ai_tag_log WHERE tstamp < ?',
                [$threshold],
            );
        } catch (DbalException $exception) {
            $this->logger?->error('Kennzeichnungs-Protokoll konnte nicht bereinigt werden: '.$exception->getMessage());

            return;
        }

        if ($deleted > 0) {
            $this->logger?->info(\sprintf('%d Eintraege im Kennzeichnungs-Protokoll nach %d Tagen bereinigt.', $deleted, $this->retentionDays));
        }
    }
}
