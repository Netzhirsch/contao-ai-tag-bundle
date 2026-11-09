<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Export;

use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;

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

    /**
     * Beschriftung der Art in der CSV. Leer bleibt leer: nur die Eintraege, die eine
     * Art betreffen (Setzen, Art aendern), tragen eine.
     */
    private const KIND_LABELS = [
        'generated' => 'generiert',
        'modified' => 'bearbeitet',
    ];

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
            // Am Ende angehaengt: wer die Spalten nach Position liest, findet alles an der
            // gewohnten Stelle.
            'KI-Beteiligung',
        ]));

        $result = $this->connection->executeQuery(
            'SELECT tstamp, action, scope, filePath, detail, username, kind FROM tl_netzhirsch_ai_tag_log ORDER BY id',
        );

        while (false !== $row = $result->fetchAssociative()) {
            yield $this->line(array_filter([
                $this->date((int) $row['tstamp']),
                (string) $row['action'],
                (string) $row['scope'],
                (string) $row['filePath'],
                (string) $row['detail'],
                $withUsernames ? (string) $row['username'] : null,
                self::KIND_LABELS[(string) ($row['kind'] ?? '')] ?? '',
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
            'KI-Beteiligung',
        ]));

        // Gekennzeichnet war eine Datei, wenn der letzte Eintrag aus (gesetzt, entfernt)
        // ein "gesetzt" ist. Die Art dazu ist die des letzten Eintrags aus (gesetzt, Art
        // geaendert) bis zum Stichtag: bei einer spaeter geaenderten Art zaehlt der
        // Stand zum Stichtag, nicht der heutige.
        $result = $this->connection->executeQuery(
            <<<'SQL'
                SELECT l.filePath, l.tstamp, l.scope, l.username, k.kind AS kind
                FROM tl_netzhirsch_ai_tag_log l
                INNER JOIN (
                    SELECT filePath, MAX(id) AS latest
                    FROM tl_netzhirsch_ai_tag_log
                    WHERE tstamp <= :moment AND action IN (:set, :unset)
                    GROUP BY filePath
                ) newest ON newest.latest = l.id
                LEFT JOIN (
                    SELECT filePath, MAX(id) AS lastkind
                    FROM tl_netzhirsch_ai_tag_log
                    WHERE tstamp <= :moment AND action IN (:set, :kindChanged)
                    GROUP BY filePath
                ) newestkind ON newestkind.filePath = l.filePath
                LEFT JOIN tl_netzhirsch_ai_tag_log k ON k.id = newestkind.lastkind
                WHERE l.action = :set
                ORDER BY l.filePath
                SQL,
            [
                'moment' => $moment->getTimestamp(),
                'set' => AiTagAuditLogger::ACTION_FLAG_SET,
                'unset' => AiTagAuditLogger::ACTION_FLAG_UNSET,
                'kindChanged' => AiTagAuditLogger::ACTION_KIND_CHANGED,
            ],
        );

        while (false !== $row = $result->fetchAssociative()) {
            yield $this->line(array_filter([
                (string) $row['filePath'],
                $this->date((int) $row['tstamp']),
                (string) $row['scope'],
                $withUsernames ? (string) $row['username'] : null,
                // Eintraege aus der Zeit vor der Art tragen keine: dort galt "generiert".
                self::KIND_LABELS[AiTagOptions::normaliseKind($row['kind'] ?? null)],
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
