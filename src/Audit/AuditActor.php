<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Audit;

/**
 * Wer eine Kennzeichnung geaendert hat, wenn es nicht der angemeldete
 * Backend-Benutzer ist.
 *
 * Notwendig fuer Schreibzugriffe ueber den MCP-Server: dort gibt es keinen
 * Backend-Login, wohl aber eine aufloesbare Identitaet. Ohne diese
 * Ueberschreibung landete jede Aenderung als "system" im Protokoll und der
 * Nachweis waere wertlos.
 */
final class AuditActor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string|null $source = null,
    ) {
    }
}
