<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Export;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;

/**
 * Erzeugt den Nachweis als CSV.
 *
 * Zwei Fassungen: das vollstaendige Protokoll und der Stichtag - welche Dateien
 * an einem bestimmten Datum gekennzeichnet waren. Der Stichtag wird aus dem
 * Protokoll rekonstruiert, indem je Pfad der letzte Eintrag bis zu diesem
 * Zeitpunkt gilt.
 */
final class AiTagLogExporter
{
    /**
     * Zeichen, mit denen Excel und LibreOffice den Inhalt einer Zelle als Formel
     * auffassen. Ein Dateipfad wie "=cmd|…" wuerde sonst beim Oeffnen ausgefuehrt.
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private const DELIMITER = ';';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return \Generator<int, string> CSV-Zeilen inklusive Zeilenumbruch
     */
    public function streamLog(bool $withUsernames = true): \Generator
    {
        yield $this->line(array_filter([
            'Datum',
            'Aktion',
            'Art',
            'Datei oder Ordner',
            'Details',
            $withUsernames ? 'Benutzer' : null,
        ]));

        $result = $this->connection->executeQuery(
            'SELECT tstamp, action, scope, filePath, detail, username FROM tl_netzhirsch_ai_tag_log ORDER BY id',
        );

        while (false !== $row = $result->fetchAssociative()) {
            yield $this->line(array_filter([
                $this->date((int) $row['tstamp']),
                (string) $row['action'],
                (string) $row['scope'],
                (string) $row['filePath'],
                (string) $row['detail'],
                $withUsernames ? (string) $row['username'] : null,
            ], static fn (mixed $value): bool => null !== $value));
        }
    }

    /**
     * Rekonstruiert den Stand zu einem Zeitpunkt: je Pfad zaehlt der letzte Eintrag
     * bis dahin, und nur wenn der eine Kennzeichnung gesetzt hat, war die Datei zu
     * diesem Zeitpunkt gekennzeichnet.
     *
     * @return \Generator<int, string>
     */
    public function streamStateAt(\DateTimeInterface $moment, bool $withUsernames = true): \Generator
    {
        yield $this->line(array_filter([
            'Datei oder Ordner',
            'Gekennzeichnet seit',
            'Art',
            $withUsernames ? 'Benutzer' : null,
        ]));

        $result = $this->connection->executeQuery(
            <<<'SQL'
                SELECT l.filePath, l.tstamp, l.scope, l.username
                FROM tl_netzhirsch_ai_tag_log l
                INNER JOIN (
                    SELECT filePath, MAX(id) AS latest
                    FROM tl_netzhirsch_ai_tag_log
                    WHERE tstamp <= :moment AND action IN (:set, :unset)
                    GROUP BY filePath
                ) newest ON newest.latest = l.id
                WHERE l.action = :set
                ORDER BY l.filePath
                SQL,
            [
                'moment' => $moment->getTimestamp(),
                'set' => AiTagAuditLogger::ACTION_FLAG_SET,
                'unset' => AiTagAuditLogger::ACTION_FLAG_UNSET,
            ],
        );

        while (false !== $row = $result->fetchAssociative()) {
            yield $this->line(array_filter([
                (string) $row['filePath'],
                $this->date((int) $row['tstamp']),
                (string) $row['scope'],
                $withUsernames ? (string) $row['username'] : null,
            ], static fn (mixed $value): bool => null !== $value));
        }
    }

    public function filename(\DateTimeInterface|null $moment): string
    {
        return null === $moment
            ? 'ki-kennzeichnung-protokoll.csv'
            : 'ki-kennzeichnung-stichtag-'.$moment->format('Y-m-d').'.csv';
    }

    /**
     * @param list<string> $columns
     */
    private function line(array $columns): string
    {
        return implode(self::DELIMITER, array_map($this->cell(...), $columns))."\r\n";
    }

    private function cell(string $value): string
    {
        // Formeln neutralisieren, bevor irgendetwas anderes passiert
        if ('' !== $value && \in_array($value[0], self::FORMULA_PREFIXES, true)) {
            $value = "'".$value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * In der Zeitzone der Installation, nicht in UTC: der Export soll dieselben
     * Zeiten zeigen wie das Backend, sonst widerspricht der Nachweis der Ansicht, aus
     * der er stammt.
     */
    private function date(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s')
        ;
    }
}
