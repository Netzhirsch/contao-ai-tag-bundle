<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\License;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Spricht mit dem Lizenzserver. Die Basis-URL ist einkompiliert, damit ein Kunde
 * sie nie setzen muss; der Konfigurationsschluessel dient nur der Entwicklung.
 *
 *   POST {server}/trial {product, domain, account_email, instance_secret}
 *   POST {server}/renew {product, domain, token, instance_secret}
 *   POST {server}/checkout-session {product, domain, account_email, plan?}
 *   POST {server}/portal-session {product, domain, token, instance_secret}
 *
 * Der Client entscheidet nichts ueber die Lizenz: er holt das frisch signierte
 * Token und legt es in den LicenseStore. Geprueft wird offline im LicenseToken.
 */
final class RenewalClient
{
    /**
     * Kuerzere Zeitgrenze, wenn eine Backend-Seite auf die Antwort wartet.
     */
    public const INTERACTIVE_TIMEOUT_SECONDS = 4;

    /**
     * Produktionsserver des Herstellers, einkompiliert. Kein Sicherheitsmerkmal: wer ihn
     * umbiegt, kann damit kein gueltiges Token erzeugen (dafuer braucht es den geheimen
     * Schluessel) - er macht nur die Lizenzierung der eigenen Installation kaputt.
     */
    private const DEFAULT_LICENSE_SERVER_URL = 'https://license.netzhirsch.de';

    /**
     * Hoechstens ein automatischer Erneuerungsversuch pro Fenster.
     */
    private const RENEW_THROTTLE_SECONDS = 6 * 3600;

    /**
     * Zeitgrenze fuer Aufrufe im Hintergrund (Cron, CLI), in Sekunden.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LicenseStore $store,
        private readonly LicenseGate $gate,
        private readonly LoggerInterface $logger,
        /**
         * Nur fuer Entwicklung und Tests; leer heisst: der einkompilierte Server.
         */
        private readonly string $serverUrlOverride = '',
    ) {
    }

    /**
     * Holt ein Token fuer die Testphase. Eine zweite Testphase je Domain und E-Mail
     * lehnt der Server ab (HTTP 409) - dort liegt die Nicht-Wiederholbarkeit.
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int, type?: string, plan?: string}
     */
    public function startTrial(string $accountEmail): array
    {
        return $this->post('/trial', [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->gate->domain(),
            'account_email' => trim($accountEmail),
            'instance_secret' => $this->store->getInstanceSecret(),
        ]);
    }

    /**
     * Verlaengert das Token. Ein nicht fataler Fehlschlag (gedrosselt, nicht
     * erreichbar, unbezahlt) liefert ok=false und laesst das gespeicherte Token
     * unberuehrt - es bleibt gueltig, bis es wirklich ablaeuft, plus Karenz.
     *
     * Die EINE Ausnahme ist ein ausdrueckliches `revoked` des Servers: dann wird das
     * Token sofort geloescht, ohne Karenz. Ein reiner Verbindungsfehler landet dort
     * nie, ein Serverausfall kann also keine zahlende Installation lahmlegen.
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int, type?: string, plan?: string}
     */
    public function renew(bool $force = false, int|null $timeoutSeconds = null): array
    {
        if (!$force && time() - $this->store->getLastRenewAt() < self::RENEW_THROTTLE_SECONDS) {
            return ['ok' => false, 'error' => 'throttled', 'message' => 'Kuerzlich erneuert, uebersprungen.'];
        }

        $result = $this->post(
            '/renew',
            [
                'product' => LicenseToken::PRODUCT,
                'domain' => $this->gate->domain(),
                'token' => $this->store->getToken(),
                // Nachweis, dass DIESE Installation die Lizenz besitzt. Leer nur bei der
                // Erstaktivierung - danach lehnt der Server einen Anspruch ohne passendes
                // Geheimnis ab (`instance_mismatch`), die Domain allein genuegt nicht mehr.
                'instance_secret' => $this->store->getInstanceSecret(),
            ],
            $timeoutSeconds,
        );

        // Den VERSUCH vermerken, damit eine scheiternde Installation (Server nicht
        // erreichbar, unbezahlt, falsche Domain) nicht stuendlich anklopft. Ein
        // erzwungener Fehlversuch darf die Marke nicht verschieben, sonst schiebt ein
        // Klick im Backend den naechsten echten Cron-Versuch um ein ganzes Fenster.
        if (($result['ok'] ?? false) || !$force) {
            $this->store->setLastRenewAt(time());
        }

        if (!($result['ok'] ?? false) && 'revoked' === ($result['error'] ?? '')) {
            // Der Notausschalter des Herstellers: Token weg, damit das Gate jetzt schliesst
            // und nicht erst nach der Karenz.
            $this->store->setToken('');
            $this->logger->warning('Lizenz der KI-Kennzeichnung wurde vom Server widerrufen, Token geloescht.', ['domain' => $this->gate->domain()]);
        }

        return $result;
    }

    /**
     * Erzeugt eine Stripe-Checkout-Sitzung (Abo kaufen) und liefert die von Stripe
     * gehostete https-Adresse. Karten- und SEPA-Daten werden ausschliesslich dort
     * eingegeben, niemals in Contao und niemals auf unserem Server.
     *
     * @return array{ok: bool, url?: string, error?: string, message?: string}
     */
    public function checkoutSession(string $accountEmail, string|null $plan = null): array
    {
        $body = [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->gate->domain(),
            'account_email' => trim($accountEmail),
        ];

        if (null !== $plan && '' !== trim($plan)) {
            $body['plan'] = trim($plan);
        }

        return $this->fetchUrl('/checkout-session', $body);
    }

    /**
     * Erzeugt eine Sitzung fuer das Stripe-Kundenportal (Zahlungsmittel,
     * Kuendigung, Rechnungen).
     *
     * @return array{ok: bool, url?: string, error?: string, message?: string}
     */
    public function portalSession(): array
    {
        return $this->fetchUrl('/portal-session', [
            'product' => LicenseToken::PRODUCT,
            'domain' => $this->gate->domain(),
            'token' => $this->store->getToken(),
            // Das Portal kann kuendigen und Zahlungsdaten aendern, es darf also nur von der
            // gebundenen Installation aus erreichbar sein - nicht von jedem, der die Domain
            // des Kunden kennt.
            'instance_secret' => $this->store->getInstanceSecret(),
        ]);
    }

    /**
     * Aufruf, der eine von Stripe gehostete Adresse zurueckgibt. Die Adresse wird auf
     * https geprueft, bevor der Aufrufer dorthin weiterleitet.
     *
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, url?: string, error?: string, message?: string}
     */
    private function fetchUrl(string $endpoint, array $body): array
    {
        $response = $this->request($endpoint, $body, self::DEFAULT_TIMEOUT_SECONDS);

        if (null !== $response['failure']) {
            return $response['failure'];
        }

        $url = (string) ($response['data']['url'] ?? '');

        if ('' === $url || !str_starts_with($url, 'https://')) {
            return ['ok' => false, 'error' => 'bad_response', 'message' => 'Der Lizenzserver hat keine gueltige https-Adresse geliefert.'];
        }

        return ['ok' => true, 'url' => $url];
    }

    /**
     * Aufruf, der ein neues Token zurueckgibt.
     *
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, error?: string, message?: string, expires_at?: int, type?: string, plan?: string}
     */
    private function post(string $endpoint, array $body, int|null $timeoutSeconds = null): array
    {
        $response = $this->request($endpoint, $body, $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS);

        if (null !== $response['failure']) {
            return $response['failure'];
        }

        $data = $response['data'];
        $token = (string) ($data['token'] ?? '');

        if ('' === $token || !$this->store->setToken($token)) {
            return ['ok' => false, 'error' => 'bad_response', 'message' => 'Der Lizenzserver hat kein speicherbares Token geliefert.'];
        }

        // Das instance_secret gibt der Server genau einmal heraus - bei der Bindung an
        // diese Installation. Dauerhaft speichern; jeder spaetere Aufruf legt es als
        // Besitznachweis vor. Es wird nie protokolliert.
        $instanceSecret = (string) ($data['instance_secret'] ?? '');

        if ('' !== $instanceSecret) {
            $this->store->setInstanceSecret($instanceSecret);
        }

        // `type` unterscheidet Testphase von bezahlter oder interner Lizenz, `plan`
        // kennzeichnet die interne Lizenz (sie verlaengert sich unbefristet). Aeltere
        // Serverstaende lassen beides weg.
        $plan = (string) ($data['plan'] ?? '');
        $this->store->setPlan($plan);

        return [
            'ok' => true,
            'expires_at' => (int) ($data['expires_at'] ?? 0),
            'type' => (string) ($data['type'] ?? ''),
            'plan' => $plan,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{data: array<string, mixed>, failure: array{ok: bool, error: string, message: string}|null}
     */
    private function request(string $endpoint, array $body, int $timeoutSeconds): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->serverUrl().$endpoint,
                [
                    'json' => $body,
                    'timeout' => $timeoutSeconds,
                ],
            );

            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $exception) {
            // Bewusst ohne Token und ohne instance_secret im Kontext.
            $this->logger->warning('Anfrage an den Lizenzserver fehlgeschlagen.', ['endpoint' => $endpoint, 'exception' => $exception]);

            return ['data' => [], 'failure' => ['ok' => false, 'error' => 'unreachable', 'message' => $exception->getMessage()]];
        }

        if ($status >= 400) {
            return ['data' => $data, 'failure' => [
                'ok' => false,
                'error' => (string) ($data['error'] ?? 'http_'.$status),
                'message' => (string) ($data['message'] ?? 'Der Lizenzserver hat HTTP '.$status.' geliefert.'),
            ]];
        }

        return ['data' => $data, 'failure' => null];
    }

    private function serverUrl(): string
    {
        $override = trim($this->serverUrlOverride);

        return rtrim('' !== $override ? $override : self::DEFAULT_LICENSE_SERVER_URL, '/');
    }
}
