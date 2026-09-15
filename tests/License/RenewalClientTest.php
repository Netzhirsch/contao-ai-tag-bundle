<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use Netzhirsch\ContaoAiTagBundle\License\RenewalClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class RenewalClientTest extends TestCase
{
    private string $projectDir;

    /**
     * @var list<array{method: string, url: string, body: array<string, mixed>}>
     */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ai-tag-renewal-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0o777, true);
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        $file = $this->projectDir.'/var/netzhirsch-ai-tag/license.json';

        if (is_file($file)) {
            unlink($file);
        }

        foreach (['/var/netzhirsch-ai-tag', '/var', ''] as $directory) {
            if (is_dir($this->projectDir.$directory)) {
                rmdir($this->projectDir.$directory);
            }
        }
    }

    public function testStoresTokenSecretAndPlan(): void
    {
        $store = $this->store();
        $client = $this->client($store, [$this->json(200, [
            'token' => 'payload.signature',
            'expires_at' => 1_800_000_000,
            'type' => 'full',
            'plan' => 'monthly',
            'instance_secret' => 'geheim',
        ])]);

        $result = $client->renew(true);

        $this->assertTrue($result['ok']);
        $this->assertSame('payload.signature', $store->getToken());
        $this->assertSame('geheim', $store->getInstanceSecret());
        $this->assertSame('monthly', $store->getPlan());
    }

    /**
     * Produkt, Domain und Besitznachweis muessen bei jedem Aufruf mitgehen - ohne das
     * kann der Server die Lizenz nicht zuordnen und die Bindung nicht pruefen.
     */
    public function testSendsProductDomainAndInstanceSecret(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');
        $store->setInstanceSecret('geheim');

        $this->client($store, [$this->json(200, ['token' => 'neues.token'])])->renew(true);

        $this->assertSame('https://license.example/renew', $this->requests[0]['url']);
        $this->assertSame(LicenseToken::PRODUCT, $this->requests[0]['body']['product']);
        $this->assertSame('kunde.de', $this->requests[0]['body']['domain']);
        $this->assertSame('altes.token', $this->requests[0]['body']['token']);
        $this->assertSame('geheim', $this->requests[0]['body']['instance_secret']);
    }

    /**
     * Die Versionsmeldung: der Server soll im Supportfall sehen, welcher Stand
     * laeuft. Sie haengt an post(), damit sie bei beiden Aufrufen mitgeht und keiner
     * sie vergessen kann.
     */
    public function testSendsBundleContaoAndPhpVersion(): void
    {
        $store = $this->store();
        $client = $this->client($store, [
            $this->json(200, ['token' => 'neues.token']),
            $this->json(200, ['token' => 'noch.neuer']),
        ]);

        $client->renew(true);
        $client->startTrial('admin@kunde.de');

        foreach ($this->requests as $request) {
            foreach (['bundle_version', 'contao_version', 'php_version'] as $field) {
                $this->assertArrayHasKey($field, $request['body']);
                $this->assertIsString($request['body'][$field]);
                $this->assertNotSame('', $request['body'][$field]);
            }

            // Ohne die Zusaetze mancher Distributionen - die verwirft der Server.
            $this->assertSame(
                PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
                $request['body']['php_version'],
            );
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._+-]{1,32}$/', $request['body']['php_version']);
        }
    }

    /**
     * Nicht dorthin, wo sie nichts beitragen: eine Sitzung bei Stripe entsteht ohnehin nur
     * aus einer Installation, die spaetestens alle sechs Stunden erneuert.
     */
    public function testSendsNoVersionsWithStripeSessions(): void
    {
        $store = $this->store();
        $client = $this->client($store, [
            $this->json(200, ['url' => 'https://checkout.stripe.com/c/pay/abc']),
            $this->json(200, ['url' => 'https://billing.stripe.com/p/session/abc']),
        ]);

        $client->checkoutSession('admin@kunde.de');
        $client->portalSession();

        foreach ($this->requests as $request) {
            $this->assertArrayNotHasKey('bundle_version', $request['body']);
            $this->assertArrayNotHasKey('contao_version', $request['body']);
            $this->assertArrayNotHasKey('php_version', $request['body']);
        }
    }

    /**
     * Der Hinweis auf eine neuere Fassung kommt aus der Lizenzantwort und wird
     * gespeichert, damit er auch zwischen zwei Serveraufrufen steht - der Cron laeuft
     * hoechstens alle sechs Stunden.
     */
    public function testKeepsAnAnnouncedVersionFromTheResponse(): void
    {
        $store = $this->store();

        $this->client($store, [$this->json(200, [
            'token' => 'neues.token',
            'latest_version' => '1.1.0',
            'release_notes_url' => 'https://example.com/notes',
            'security_release' => true,
        ])])->renew(true);

        $this->assertSame('1.1.0', $store->getLatestVersion());
        $this->assertSame('https://example.com/notes', $store->getReleaseNotesUrl());
        $this->assertTrue($store->isSecurityRelease());
    }

    /**
     * Rueckwaertskompatibilitaet ist hier die Bedingung, nicht der Wunsch: die Felder
     * duerfen fehlen, null sein oder Unsinn enthalten, ohne dass die Lizenz darunter
     * leidet. Das Token wird in jedem Fall uebernommen, der Hinweis bleibt leer.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('unusableAnnouncementProvider')]
    public function testAnUnusableAnnouncementChangesNothing(array $payload): void
    {
        $store = $this->store();
        $store->setUpdateNotice('1.1.0', 'https://example.com/notes', true);

        $result = $this->client($store, [$this->json(200, ['token' => 'neues.token'] + $payload)])->renew(true);

        $this->assertTrue($result['ok']);
        $this->assertSame('neues.token', $store->getToken());
        $this->assertSame('', $store->getLatestVersion());
        $this->assertSame('', $store->getReleaseNotesUrl());
        $this->assertFalse($store->isSecurityRelease());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableAnnouncementProvider(): iterable
    {
        yield 'Felder fehlen' => [[]];
        yield 'alles null' => [['latest_version' => null, 'release_notes_url' => null, 'security_release' => null]];
        yield 'Zahl statt Zeichenkette' => [['latest_version' => 110, 'release_notes_url' => 7, 'security_release' => 1]];
        yield 'Liste statt Zeichenkette' => [['latest_version' => ['1.1.0'], 'release_notes_url' => ['https://example.com'], 'security_release' => []]];
        yield 'Markup in der Version' => [['latest_version' => '<script>alert(1)</script>', 'release_notes_url' => 'https://example.com/notes', 'security_release' => true]];
    }

    public function testThrottlesUnforcedRenewals(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');
        $store->setLastRenewAt(time());

        $result = $this->client($store, [])->renew();

        $this->assertFalse($result['ok']);
        $this->assertSame('throttled', $result['error']);
        $this->assertSame([], $this->requests, 'Gedrosselt heisst: gar kein Aufruf.');
    }

    /**
     * Der Notausschalter des Herstellers. Nur hier darf das Token verschwinden.
     */
    public function testClearsTheTokenWhenRevoked(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');

        $result = $this->client($store, [$this->json(403, ['error' => 'revoked', 'message' => 'Widerrufen.'])])->renew(true);

        $this->assertFalse($result['ok']);
        $this->assertSame('revoked', $result['error']);
        $this->assertSame('', $store->getToken());
    }

    /**
     * Bei unbezahltem Abo laeuft das Token mit Karenz aus - sofort loeschen wuerde
     * einen Kunden mitten in einer Zahlungsklaerung aussperren.
     */
    public function testKeepsTheTokenWhenTheSubscriptionIsInactive(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');

        $result = $this->client($store, [$this->json(402, ['error' => 'subscription_inactive'])])->renew(true);

        $this->assertSame('subscription_inactive', $result['error']);
        $this->assertSame('altes.token', $store->getToken());
    }

    /**
     * Ein Serverausfall darf keine zahlende Installation lahmlegen.
     */
    public function testKeepsTheTokenWhenTheServerIsUnreachable(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');

        $result = $this->client($store, [static function (): MockResponse { throw new TransportException('Netz weg'); }])->renew(true);

        $this->assertSame('unreachable', $result['error']);
        $this->assertSame('altes.token', $store->getToken());
    }

    /**
     * Ein erzwungener Fehlversuch (Klick im Backend) darf die Drosselmarke nicht
     * verschieben, sonst verschiebt er den naechsten echten Cron-Versuch um bis zu
     * sechs Stunden.
     */
    public function testAFailedForcedRenewalDoesNotMoveTheThrottleMarker(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');

        $this->client($store, [$this->json(404, ['error' => 'unknown_license'])])->renew(true);

        $this->assertSame(0, $store->getLastRenewAt());
    }

    public function testAFailedUnforcedRenewalMovesTheThrottleMarker(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');

        $this->client($store, [$this->json(404, ['error' => 'unknown_license'])])->renew();

        $this->assertGreaterThan(0, $store->getLastRenewAt(), 'Sonst klopft eine scheiternde Installation stuendlich an.');
    }

    public function testTrialSendsTheAccountEmail(): void
    {
        $client = $this->client($this->store(), [$this->json(200, ['token' => 'neues.token', 'type' => 'trial'])]);

        $result = $client->startTrial('  admin@kunde.de ');

        $this->assertTrue($result['ok']);
        $this->assertSame('trial', $result['type']);
        $this->assertSame('https://license.example/trial', $this->requests[0]['url']);
        $this->assertSame('admin@kunde.de', $this->requests[0]['body']['account_email']);
    }

    /**
     * Bezahlt wird ausschliesslich auf den von Stripe gehosteten Seiten. Am anderen Ende
     * der Weiterleitung sitzt ein angemeldeter Administrator: waere der Lizenzserver
     * kompromittiert oder eine Antwort untergeschoben, waere eine beliebige Adresse eine
     * Weiterleitung auf eine Phishing-Seite. Deshalb https UND Stripe-Host.
     */
    #[DataProvider('rejectedUrlProvider')]
    public function testFollowsOnlyStripeUrls(string $url): void
    {
        $client = $this->client($this->store(), [$this->json(200, ['url' => $url])]);

        $result = $client->checkoutSession('admin@kunde.de');

        $this->assertFalse($result['ok']);
        $this->assertSame('bad_response', $result['error']);
        $this->assertArrayNotHasKey('url', $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrlProvider(): iterable
    {
        yield 'ohne Verschluesselung' => ['http://checkout.stripe.com/c/pay/abc'];
        yield 'fremder Host' => ['https://phish.example/pay'];
        yield 'Stripe im Pfad' => ['https://phish.example/checkout.stripe.com/pay'];
        yield 'Stripe als Praefix' => ['https://stripe.com.phish.example/pay'];
        yield 'Stripe als Benutzername' => ['https://checkout.stripe.com@phish.example/pay'];
        yield 'leer' => [''];
    }

    public function testReturnsTheStripeUrl(): void
    {
        $client = $this->client($this->store(), [$this->json(200, ['url' => 'https://checkout.stripe.com/c/pay/abc'])]);

        $this->assertSame('https://checkout.stripe.com/c/pay/abc', $client->checkoutSession('admin@kunde.de')['url']);
    }

    public function testAcceptsTheStripeCustomerPortal(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');
        $client = $this->client($store, [$this->json(200, ['url' => 'https://billing.stripe.com/p/session/abc'])]);

        $this->assertSame('https://billing.stripe.com/p/session/abc', $client->portalSession()['url']);
    }

    public function testAnswerWithoutATokenIsNotStored(): void
    {
        $store = $this->store();
        $store->setToken('altes.token');

        $result = $this->client($store, [$this->json(200, ['expires_at' => 1_800_000_000])])->renew(true);

        $this->assertSame('bad_response', $result['error']);
        $this->assertSame('altes.token', $store->getToken());
    }

    /**
     * Token und instance_secret duerfen nie ins Protokoll geraten - ein Logeintrag
     * landet schnell in einem Ticket.
     */
    public function testNeverLogsTokenOrSecret(): void
    {
        $store = $this->store();
        $store->setToken('sehr.geheimes.token');
        $store->setInstanceSecret('sehr-geheimes-secret');

        $logger = new class() extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $lines = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message.' '.print_r(array_keys($context), true).' '.implode(' ', array_filter($context, is_scalar(...)));
            }
        };

        $client = new RenewalClient(
            new MockHttpClient([static function (): MockResponse { throw new TransportException('Netz weg'); }]),
            $store,
            $this->gate($store),
            $logger,
            'https://license.example',
        );

        $client->renew(true);

        $this->assertNotSame([], $logger->lines);

        foreach ($logger->lines as $line) {
            $this->assertStringNotContainsString('sehr.geheimes.token', $line);
            $this->assertStringNotContainsString('sehr-geheimes-secret', $line);
        }
    }

    /**
     * @param list<MockResponse|callable> $responses
     */
    private function client(LicenseStore $store, array $responses): RenewalClient
    {
        $recorder = function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => json_decode((string) ($options['body'] ?? '{}'), true) ?? [],
            ];

            $next = array_shift($responses);

            if (null === $next) {
                throw new \LogicException('Unerwarteter Aufruf an '.$url);
            }

            return \is_callable($next) ? $next() : $next;
        };

        return new RenewalClient(
            new MockHttpClient($recorder),
            $store,
            $this->gate($store),
            new class() extends AbstractLogger {
                /**
                 * @param array<string, mixed> $context
                 */
                public function log(mixed $level, \Stringable|string $message, array $context = []): void
                {
                }
            },
            'https://license.example',
        );
    }

    private function store(): LicenseStore
    {
        return new LicenseStore($this->projectDir);
    }

    private function gate(LicenseStore $store): LicenseGate
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://www.kunde.de/contao'));

        return new LicenseGate(new LicenseToken(''), $store, $requestStack);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): MockResponse
    {
        return new MockResponse(
            (string) json_encode($payload),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        );
    }
}
