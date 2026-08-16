<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Export;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Netzhirsch\ContaoAiTagBundle\Export\AiTagLogExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiTagLogExporterTest extends TestCase
{
    /**
     * Ein Dateipfad, der mit = + - oder @ beginnt, wird von Excel und LibreOffice als
     * Formel ausgefuehrt. Das ist der klassische Fehler bei CSV-Exporten, und die
     * Werte hier kommen aus Dateinamen, die jeder Redakteur setzen kann.
     */
    #[DataProvider('dangerousValueProvider')]
    public function testNeutralisesFormulas(string $value): void
    {
        $line = $this->firstDataLine($this->exporter([$this->logRow(['filePath' => $value])])->streamLog());

        $this->assertStringContainsString('"\''.$value.'"', $line, 'Der Wert muss als Text erzwungen werden.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousValueProvider(): iterable
    {
        yield 'Gleichheitszeichen' => ['=1+1'];
        yield 'Plus' => ['+1'];
        yield 'Minus' => ['-1'];
        yield 'Klammeraffe' => ['@SUM(A1)'];
        yield 'Kommando' => ['=cmd|\' /c calc\'!A1'];
    }

    public function testQuotesAndEscapesRegularValues(): void
    {
        $line = $this->firstDataLine($this->exporter([$this->logRow(['filePath' => 'files/mit "Anfuehrungszeichen".jpg'])])->streamLog());

        $this->assertStringContainsString('"files/mit ""Anfuehrungszeichen"".jpg"', $line);
    }

    public function testOmitsUserNamesOnRequest(): void
    {
        $lines = iterator_to_array($this->exporter([$this->logRow(['username' => 'k.jones'])])->streamLog(false));

        $this->assertStringNotContainsString('Benutzer', $lines[0], 'Die Spalte darf dann gar nicht erst auftauchen.');
        $this->assertStringNotContainsString('k.jones', $lines[1]);
    }

    public function testKeepsUserNamesByDefault(): void
    {
        $lines = iterator_to_array($this->exporter([$this->logRow(['username' => 'k.jones'])])->streamLog());

        $this->assertStringContainsString('Benutzer', $lines[0]);
        $this->assertStringContainsString('k.jones', $lines[1]);
    }

    public function testTheAsOfExportAsksForTheStateAtThatMoment(): void
    {
        $moment = new \DateTimeImmutable('2026-03-12 23:59:59');
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('MAX(id)'),
                $this->callback(
                    static fn (array $parameters): bool => $parameters['moment'] === $moment->getTimestamp()
                        && 'flag_set' === $parameters['set']
                        && 'flag_unset' === $parameters['unset'],
                ),
            )
            ->willReturn($this->dbalResult([['filePath' => 'files/bild.jpg', 'tstamp' => 1_772_000_000, 'scope' => 'file', 'username' => 'admin']]))
        ;

        $lines = iterator_to_array((new AiTagLogExporter($connection))->streamStateAt($moment));

        $this->assertStringContainsString('Gekennzeichnet seit', $lines[0]);
        $this->assertStringContainsString('files/bild.jpg', $lines[1]);
    }

    /**
     * Das Backend zeigt Zeiten in der Zeitzone der Installation. Ein Nachweis, der
     * daneben in UTC steht, widerspricht der Ansicht, aus der er stammt.
     */
    public function testFormatsTimesInTheConfiguredTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            // 2026-03-01 10:00:00 Berliner Zeit
            $line = $this->firstDataLine($this->exporter([$this->logRow(['tstamp' => 1_772_355_600])])->streamLog());
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertStringContainsString('2026-03-01 10:00:00', $line);
    }

    public function testFilenameCarriesTheDate(): void
    {
        $exporter = $this->exporter([]);

        $this->assertSame('ki-kennzeichnung-protokoll.csv', $exporter->filename(null));
        $this->assertSame('ki-kennzeichnung-stichtag-2026-03-12.csv', $exporter->filename(new \DateTimeImmutable('2026-03-12')));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function logRow(array $overrides = []): array
    {
        return [
            'tstamp' => 1_772_000_000,
            'action' => 'flag_set',
            'scope' => 'file',
            'filePath' => 'files/bild.jpg',
            'detail' => '',
            'username' => 'admin',
            ...$overrides,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function exporter(array $rows): AiTagLogExporter
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('executeQuery')
            ->willReturn($this->dbalResult($rows))
        ;

        return new AiTagLogExporter($connection);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function dbalResult(array $rows): Result
    {
        $result = $this->createStub(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn(...[...$rows, false])
        ;

        return $result;
    }

    /**
     * @param \Generator<int, string> $lines
     */
    private function firstDataLine(\Generator $lines): string
    {
        $all = iterator_to_array($lines);

        return $all[1] ?? '';
    }
}
