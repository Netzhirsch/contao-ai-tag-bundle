<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\Cron\PurgeAiTagLogCron;
use PHPUnit\Framework\TestCase;

class PurgeAiTagLogCronTest extends TestCase
{
    public function testDeletesEntriesOlderThanTheRetentionPeriod(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                'DELETE FROM tl_netzhirsch_ai_tag_log WHERE tstamp < ?',
                $this->callback(
                    static function (array $parameters): bool {
                        $expected = time() - 1095 * 86400;

                        // Eine Sekunde Toleranz, damit der Test nicht an der Laufzeit haengt
                        return abs($parameters[0] - $expected) <= 1;
                    },
                ),
            )
            ->willReturn(3)
        ;

        (new PurgeAiTagLogCron($connection, 1095))();
    }

    public function testKeepsEverythingWhenRetentionIsDisabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->never())
            ->method('executeStatement')
        ;

        (new PurgeAiTagLogCron($connection, 0))();
    }
}
