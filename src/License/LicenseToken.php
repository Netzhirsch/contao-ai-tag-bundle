<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\License;

/**
 * Prueft ein Lizenz-Token des Netzhirsch-Lizenzservers - vollstaendig offline.
 *
 * Format: `base64url(payloadJson).base64url(signatur)`, die Signatur ist eine
 * abgetrennte Ed25519-Signatur ueber genau die Payload-Bytes. Die Payload traegt:
 *   product string muss self::PRODUCT entsprechen
 *   domain string der lizenzierte Host (normalisiert, ohne Schema/www/Port)
 *   type string 'trial' | 'full'
 *   license_id string serverseitige Kennung (Nachweis, Widerruf)
 *   issued_at int Unix-Zeitstempel
 *   expires_at int Unix-Zeitstempel
 *
 * Der passende geheime Schluessel liegt ausschliesslich auf dem Lizenzserver. Der
 * oeffentliche Schluessel unten kann nur pruefen, niemals ausstellen - deshalb
 * darf er im Bundle mitgeliefert werden.
 *
 * Er steht bewusst im Code und nicht in der Konfiguration: einen konfigurierbaren
 * Schluessel wuerde man gegen einen selbst erzeugten tauschen und sich beliebige
 * Tokens signieren. Den Quellcode zu patchen ist die akzeptierte (und nicht
 * verhinderbare) Grenze; ein Konfigurationseintrag darf es nicht sein.
 *
 * Bewusst 8.1-tauglich (das Bundle unterstuetzt Contao 5.3 / PHP ^8.1): keine
 * typisierten Klassenkonstanten, keine 8.2/8.3-Syntax.
 */
final class LicenseToken
{
    /**
     * Oeffentlicher Ed25519-Schluessel des Herstellers, Base64 der rohen 32 Bytes.
     *
     * Er kann Tokens nur PRUEFEN, niemals ausstellen - der passende geheime
     * Schluessel liegt ausschliesslich auf dem Lizenzserver (Umgebungsvariable
     * LICENSE_SIGNING_SECRET_AI_TAG, eigenes Paar je Produkt). Deshalb darf er hier
     * im Code stehen, und genau hier muss er auch stehen: aus einer
     * Konfigurationsdatei liesse er sich gegen einen selbst erzeugten tauschen.
     *
     * Leer = diese Fassung ist nicht lizenzpflichtig, das Gate laesst dann alles
     * durch (siehe isArmed()). Damit liess sich das Bundle ausrollen und die internen
     * Lizenzen ausstellen, bevor mit diesem Wert scharf geschaltet wurde.
     */
    public const VENDOR_PUBLIC_KEY_B64 = 'oXwrdfKe5p1/g+gQOS+33OQCZ4YITfwvrXOwGoDAEAI=';

    /**
     * Produkt-Slug auf dem Lizenzserver: der Composer-Paketname.
     */
    public const PRODUCT = 'netzhirsch/contao-ai-tag-bundle';

    /**
     * Erlaubter Nachlauf der eigenen Uhr gegenueber dem Server, in Sekunden.
     */
    public const CLOCK_SKEW_TOLERANCE = 300;

    /**
     * Laenge eines rohen Ed25519-Public-Keys. Als Literal, damit die Pruefung auch
     * ohne ext-sodium laeuft.
     */
    private const PUBLIC_KEY_BYTES = 32;

    /**
     * @param string $publicKeyB64 Standard ist der mitgelieferte Herstellerschluessel;
     *                             Tests uebergeben einen eigenen
     */
    public function __construct(private readonly string $publicKeyB64 = self::VENDOR_PUBLIC_KEY_B64)
    {
    }

    /**
     * Ist ueberhaupt ein Schluessel einkompiliert? Ohne ihn kann nichts geprueft
     * werden, und die Fassung gilt als nicht lizenzpflichtig.
     */
    public function isArmed(): bool
    {
        $public = base64_decode($this->publicKeyB64, true);

        return false !== $public && self::PUBLIC_KEY_BYTES === \strlen($public);
    }

    /**
     * @return array{valid: bool, reason: string, type: string, expires_at: int, issued_at: int, now_ref: int, license_id: string}
     */
    public function verify(string $token, string $host, int $seenHighWater = 0): array
    {
        // Ein zurueckgestellter Systemzeitpunkt darf eine Lizenz nicht verlaengern:
        // verglichen wird gegen den hoechsten je gesehenen Zeitstempel.
        $reference = max(time(), $seenHighWater);

        $fail = static fn (string $reason): array => [
            'valid' => false,
            'reason' => $reason,
            'type' => '',
            'expires_at' => 0,
            'issued_at' => 0,
            'now_ref' => $reference,
            'license_id' => '',
        ];

        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return $fail('sodium_unavailable');
        }

        $token = trim($token);

        if ('' === $token) {
            return $fail('no_token');
        }

        $parts = explode('.', $token);

        if (2 !== \count($parts)) {
            return $fail('malformed');
        }

        $payloadJson = self::b64urlDecode($parts[0]);
        $signature = self::b64urlDecode($parts[1]);
        $public = base64_decode($this->publicKeyB64, true);

        if (false === $payloadJson || false === $signature || false === $public || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($public)) {
            return $fail('malformed');
        }

        // Der gefaelschte oder veraenderte Fall: den geheimen Schluessel hat nur
        // der Hersteller.
        if (!sodium_crypto_sign_verify_detached($signature, $payloadJson, $public)) {
            return $fail('bad_signature');
        }

        $payload = json_decode($payloadJson, true);

        if (!\is_array($payload)) {
            return $fail('malformed');
        }

        if (self::PRODUCT !== ($payload['product'] ?? null)) {
            return $fail('wrong_product');
        }

        if (!hash_equals(self::normalizeHost((string) ($payload['domain'] ?? '')), self::normalizeHost($host))) {
            return $fail('wrong_domain');
        }

        $issuedAt = (int) ($payload['issued_at'] ?? 0);
        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        $base = [
            'type' => (string) ($payload['type'] ?? 'full'),
            'expires_at' => $expiresAt,
            'issued_at' => $issuedAt,
            'now_ref' => $reference,
            'license_id' => (string) ($payload['license_id'] ?? ''),
        ];

        // Kleine Toleranz nach unten: issued_at stammt von der Uhr des Servers. Eine
        // Installation, die einige Minuten nachlaeuft, wuerde sonst direkt nach der
        // Aktivierung hart scheitern - und clock_tampered bekommt keine Karenz. Der
        // Schutz gegen Zurueckstellen bleibt, weil die High-Water-Mark $reference haelt.
        if ($reference < $issuedAt - self::CLOCK_SKEW_TOLERANCE) {
            return ['valid' => false, 'reason' => 'clock_tampered'] + $base;
        }

        if ($reference > $expiresAt) {
            return ['valid' => false, 'reason' => 'expired'] + $base;
        }

        return ['valid' => true, 'reason' => 'ok'] + $base;
    }

    /**
     * Der kanonische lizenzierte Host: bevorzugt die konfigurierte Backend-URL (die
     * gibt es auch im Cron, wo kein Request existiert), sonst der Host des laufenden
     * Requests. Gate und Erneuerung muessen identisch normalisieren, sonst scheitert
     * ein auf die Backend-URL ausgestelltes Token am Request-Host (www vs. ohne).
     */
    public static function resolveDomain(string $backendUrl, string|null $requestHost): string
    {
        $backendUrl = trim($backendUrl);

        if ('' !== $backendUrl) {
            // parse_url('kunde.de') hat keinen Host (ohne Schema wird alles als Pfad
            // gelesen) - deshalb auch den nackten Hostnamen akzeptieren.
            $host = parse_url($backendUrl, PHP_URL_HOST);

            if (!\is_string($host) || '' === $host) {
                $host = parse_url('https://'.ltrim($backendUrl, '/'), PHP_URL_HOST);
            }

            if (\is_string($host) && '' !== $host) {
                return self::normalizeHost($host);
            }
        }

        return self::normalizeHost((string) $requestHost);
    }

    /**
     * Die Domain-Angabe eines gespeicherten Tokens, OHNE Signaturpruefung.
     *
     * Nur dort benutzt, wo es keinen Request-Host gibt (CLI, Cron) und keine
     * Backend-URL konfiguriert ist: die Erneuerung muss dem Server trotzdem sagen,
     * WELCHE Lizenz gemeint ist. Unbedenklich, weil damit nichts gewaehrt wird - der
     * Server prueft erneut, und auf dem Auslieferungspfad (mit echtem Request-Host)
     * bleibt die Signaturpruefung unberuehrt. Niemals als Grundlage einer
     * Berechtigungsentscheidung verwenden.
     */
    public static function peekDomain(string $token): string
    {
        $parts = explode('.', trim($token));

        if (2 !== \count($parts)) {
            return '';
        }

        $payloadJson = self::b64urlDecode($parts[0]);

        if (false === $payloadJson) {
            return '';
        }

        $payload = json_decode($payloadJson, true);

        return \is_array($payload) ? self::normalizeHost((string) ($payload['domain'] ?? '')) : '';
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return (string) preg_replace('/^www\./', '', $host);
    }

    private static function b64urlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
