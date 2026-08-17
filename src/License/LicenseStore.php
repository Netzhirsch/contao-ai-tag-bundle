<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\License;

/**
 * Haelt Token und Betriebsdaten der Lizenz in `var/netzhirsch-ai-tag/license.json`.
 *
 *   token string das aktuelle signierte Token ('' = keines)
 *   instance_secret string beweist dem Server, dass DIESE Installation die Lizenz
 *                           besitzt (wird genau einmal ausgegeben, bei der Bindung)
 *   plan string letzter gemeldeter Plan (nur zur Anzeige)
 *   hwm int hoechster je gesehener Zeitstempel (Schutz gegen
 *                           Zurueckstellen der Uhr), nur vorwaerts
 *   last_renew_at int Zeitpunkt des letzten Erneuerungsversuchs (Drosselung)
 *
 * Die Datei liegt unter var/ und ist damit kein Konfigurationsgegenstand: sie
 * rotiert, wird von Cron und Backend geschrieben und nicht von Hand gepflegt.
 * Weder Token noch instance_secret gehen jemals ins Protokoll oder ins Backend.
 */
final class LicenseStore
{
    /**
     * Mindest-Vorsprung, ab dem die High-Water-Mark neu geschrieben wird (Sekunden).
     */
    private const HWM_WRITE_GRANULARITY = 3600;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function getToken(): string
    {
        return (string) ($this->load()['token'] ?? '');
    }

    public function setToken(string $token): bool
    {
        $data = $this->load();
        $data['token'] = trim($token);

        return $this->write($data);
    }

    /**
     * Das Geheimnis, das diese Installation als Inhaberin der Lizenz ausweist. Der
     * Server gibt es bei der Bindung genau einmal heraus; danach geht es bei jedem
     * Aufruf mit. Leer bis zur Erstaktivierung.
     */
    public function getInstanceSecret(): string
    {
        return (string) ($this->load()['instance_secret'] ?? '');
    }

    public function setInstanceSecret(string $secret): bool
    {
        $secret = trim($secret);

        if ('' === $secret) {
            return false;
        }

        $data = $this->load();
        $data['instance_secret'] = $secret;

        return $this->write($data);
    }

    /**
     * Der vom Server gemeldete Plan ('monthly', 'annual', 'internal', 'staging' oder
     * '' bei unbekannt). Reine Anzeige - entschieden wird allein anhand des Tokens.
     */
    public function getPlan(): string
    {
        return (string) ($this->load()['plan'] ?? '');
    }

    public function setPlan(string $plan): bool
    {
        $data = $this->load();
        $data['plan'] = trim($plan);

        return $this->write($data);
    }

    public function getHwm(): int
    {
        return (int) ($this->load()['hwm'] ?? 0);
    }

    /**
     * Setzt die High-Water-Mark hoch. Sie laeuft nur vorwaerts, damit ein
     * zurueckgestellter Systemzeitpunkt ein abgelaufenes Token nicht wieder gueltig
     * macht. Bestmoeglich, kein harter Schutz: die Datei liegt beim Kunden.
     *
     * Geschrieben wird nur bei einem nennenswerten Sprung. Das Gate ruft die Methode
     * bei jedem Bild auf; sekundenweise Schreibvorgaenge waeren sinnlose Last, und
     * jedes Lesen-Aendern-Schreiben ist ein Fenster, in dem ein parallel erneuertes
     * Token verloren gehen koennte. Eine Stunde Genauigkeit genuegt hier voellig.
     */
    public function bumpHwm(int $timestamp): void
    {
        $data = $this->load();

        if ($timestamp > (int) ($data['hwm'] ?? 0) + self::HWM_WRITE_GRANULARITY) {
            $data['hwm'] = $timestamp;
            $this->write($data);
        }
    }

    public function getLastRenewAt(): int
    {
        return (int) ($this->load()['last_renew_at'] ?? 0);
    }

    public function setLastRenewAt(int $timestamp): void
    {
        $data = $this->load();
        $data['last_renew_at'] = $timestamp;
        $this->write($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->filePath();

        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if (false === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function filePath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'netzhirsch-ai-tag'.\DIRECTORY_SEPARATOR.'license.json';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): bool
    {
        $directory = \dirname($this->filePath());

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return false;
        }

        return false !== @file_put_contents(
            $this->filePath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            LOCK_EX,
        );
    }
}
