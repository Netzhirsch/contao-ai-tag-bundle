<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\Audit\AuditActor;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Das Protokoll ist der Nachweis. Es darf weder an einer fehlenden Spalte
 * ausfallen noch eine Aenderung verschlucken - und seit der Art der
 * KI-Beteiligung muss es festhalten, ob "generiert" oder "bearbeitet"
 * gekennzeichnet wurde.
 */
class AiTagAuditLoggerTest extends TestCase
{
    public function testTheKindIsWrittenWithTheEntry(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'tl_netzhirsch_ai_tag_log',
                $this->callback(static fn (array $row): bool => 'flag_set' === $row['action'] && 'modified' === $row['kind'] && 'files/bild.jpg' === $row['filePath']),
            )
        ;

        $this->logger($connection)->log(AiTagAuditLogger::ACTION_FLAG_SET, 'files/bild.jpg', false, null, new AuditActor(1, 'admin'), 'modified');
    }

    public function testEntriesWithoutAKindKeepAnEmptyOne(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'tl_netzhirsch_ai_tag_log',
                $this->callback(static fn (array $row): bool => '' === $row['kind']),
            )
        ;

        $this->logger($connection)->log(AiTagAuditLogger::ACTION_TEXT_CHANGED, 'files/bild.jpg', false, 'Text', new AuditActor(1, 'admin'));
    }

    /**
     * Nach einem Update des Bundles fehlt die Spalte "kind", bis die Datenbank
     * aktualisiert ist. Ein Eintrag ohne die Art ist besser als gar keiner: der
     * Nachweis darf an einer fehlenden Spalte nicht ausfallen.
     */
    public function testFallsBackToAnEntryWithoutTheKindWhenTheColumnIsMissing(): void
    {
        $written = [];
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(
                function (string $table, array $row) use (&$written): int {
                    if (\array_key_exists('kind', $row)) {
                        throw $this->dbalException('Unknown column kind');
                    }

                    $written[] = $row;

                    return 1;
                },
            )
        ;

        $this->logger($connection)->log(AiTagAuditLogger::ACTION_FLAG_SET, 'files/bild.jpg', false, null, new AuditActor(1, 'admin'), 'modified');

        $this->assertCount(1, $written, 'Der Eintrag muss trotzdem im Protokoll stehen.');
        $this->assertSame('flag_set', $written[0]['action']);
        $this->assertArrayNotHasKey('kind', $written[0]);
    }

    /**
     * Scheitert auch das, darf die Redaktion nicht blockiert werden - aber es muss
     * sichtbar bleiben.
     */
    public function testAFailingProtocolIsReportedAndDoesNotThrow(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('insert')
            ->willThrowException($this->dbalException('Tabelle fehlt'))
        ;

        $sink = new class() extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $errors = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->errors[] = (string) $message;
                }
            }
        };

        $this->logger($connection, $sink)->log(AiTagAuditLogger::ACTION_FLAG_SET, 'files/bild.jpg', false, null, new AuditActor(1, 'admin'), 'generated');

        $this->assertCount(1, $sink->errors);
        $this->assertStringContainsString('nicht geschrieben', $sink->errors[0]);
    }

    private function logger(Connection $connection, AbstractLogger|null $log = null): AiTagAuditLogger
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        return new AiTagAuditLogger($connection, $this->createStub(Security::class), $translator, $log);
    }

    /**
     * Die Ausnahme, die DBAL bei einer fehlenden Spalte wirft. Der Konstruktor ist in
     * DBAL 3 und 4 derselbe; deshalb keine Weiche nach Version
     * (Doctrine\DBAL\Exception ist in 3 eine Klasse, in 4 ein Interface).
     */
    private function dbalException(string $message): \Throwable
    {
        $driver = new class($message) extends \RuntimeException implements DriverException {
            public function getSQLState(): string|null
            {
                return null;
            }
        };

        return new InvalidFieldNameException($driver, null);
    }
}
