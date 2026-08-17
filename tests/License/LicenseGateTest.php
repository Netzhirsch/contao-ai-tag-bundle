<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class LicenseGateTest extends TestCase
{
    private string $projectDir;

    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ai-tag-gate-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0o777, true);

        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
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

    /**
     * Solange kein Herstellerschluessel einkompiliert ist, ist die Fassung nicht
     * lizenzpflichtig. Das ist der Weg, das Bundle auszurollen, interne Lizenzen
     * auszustellen und erst danach scharf zu schalten.
     */
    public function testWithoutACompiledKeyEverythingIsAllowed(): void
    {
        $state = $this->gate('')->state();

        $this->assertTrue($state['active']);
        $this->assertFalse($state['armed']);
        $this->assertSame('not_enforced', $state['reason']);
    }

    public function testAValidTokenUnlocksTheLabelling(): void
    {
        $store = $this->store();
        $store->setToken($this->token());

        $state = $this->gate($this->publicKey, $store)->state();

        $this->assertTrue($state['active']);
        $this->assertTrue($state['armed']);
        $this->assertSame('ok', $state['reason']);
        $this->assertSame('full', $state['type']);
        $this->assertSame(35, $state['days_left']);
        $this->assertFalse($state['in_grace']);
    }

    public function testWithoutATokenNothingIsBurntIn(): void
    {
        $state = $this->gate($this->publicKey)->state();

        $this->assertFalse($state['active']);
        $this->assertSame('no_token', $state['reason']);
        $this->assertSame(0, $state['days_left']);
    }

    /**
     * Karenz: eine kurze Server- oder Netzstoerung darf keinen zahlenden Kunden aussperren.
     */
    public function testAJustExpiredTokenStaysActiveDuringTheGracePeriod(): void
    {
        $store = $this->store();
        $store->setToken($this->token(['issued_at' => time() - 86400, 'expires_at' => time() - 3600]));

        $state = $this->gate($this->publicKey, $store)->state();

        $this->assertTrue($state['active']);
        $this->assertTrue($state['in_grace']);
        $this->assertSame('expired', $state['reason'], 'Der Grund bleibt sichtbar, damit das Backend zur Erneuerung auffordern kann.');
        $this->assertSame(0, $state['days_left'], 'Negative Resttage waeren im Backend Unsinn.');
    }

    public function testAfterTheGraceWindowTheGateCloses(): void
    {
        $store = $this->store();
        $store->setToken($this->token(['issued_at' => time() - 30 * 86400, 'expires_at' => time() - 4 * 86400]));

        $state = $this->gate($this->publicKey, $store)->state();

        $this->assertFalse($state['active']);
        $this->assertFalse($state['in_grace']);
    }

    /**
     * Das Ergebnis wird je Token gemerkt (eine Seite mit zwanzig Bildern fragt
     * zwanzig Mal). Wechselt das Token - Erneuerung oder Widerruf -, muss die
     * naechste Frage neu entscheiden.
     */
    public function testANewTokenIsNoticedDespiteTheMemo(): void
    {
        $store = $this->store();
        $gate = $this->gate($this->publicKey, $store);

        $this->assertFalse($gate->isActive());

        $store->setToken($this->token());

        $this->assertTrue($gate->isActive());

        $store->setToken('');

        $this->assertFalse($gate->isActive(), 'Ein Widerruf muss sofort greifen.');
    }

    /**
     * Im Cron gibt es keinen Request. Ohne konfigurierte Backend-URL faellt die
     * Domain auf die Angabe des Tokens zurueck, damit der Zustand nicht als
     * wrong_domain liest.
     */
    public function testWithoutARequestTheConfiguredHostDecides(): void
    {
        $store = $this->store();
        $store->setToken($this->token());

        $configured = new LicenseGate(new LicenseToken($this->publicKey), $store, new RequestStack(), 'https://www.kunde.de/contao');
        $fromToken = new LicenseGate(new LicenseToken($this->publicKey), $store, new RequestStack(), '');

        $this->assertSame('kunde.de', $configured->domain());
        $this->assertTrue($configured->state()['active']);
        $this->assertSame('kunde.de', $fromToken->domain());
        $this->assertTrue($fromToken->state()['active']);
    }

    /**
     * Die konfigurierte Backend-URL darf keine fremde Lizenz gueltig machen: geprueft
     * wird die Signatur ueber genau diese Domain.
     */
    public function testATokenOfAnotherDomainStaysInvalid(): void
    {
        $store = $this->store();
        $store->setToken($this->token(['domain' => 'fremde-domain.de']));

        $this->assertSame('wrong_domain', $this->gate($this->publicKey, $store)->state()['reason']);
    }

    private function gate(string $publicKey, LicenseStore|null $store = null): LicenseGate
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://kunde.de/contao'));

        return new LicenseGate(new LicenseToken($publicKey), $store ?? $this->store(), $requestStack);
    }

    private function store(): LicenseStore
    {
        return new LicenseStore($this->projectDir);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        $payload = [
            'product' => LicenseToken::PRODUCT,
            'domain' => 'kunde.de',
            'type' => 'full',
            'license_id' => 'lic_1',
            'issued_at' => time() - 60,
            'expires_at' => time() + 35 * 86400,
            ...$overrides,
        ];

        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($json, (string) base64_decode($this->secretKey, true));

        return $this->b64url($json).'.'.$this->b64url($signature);
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
