<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LicenseTokenTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
    }

    public function testAcceptsATokenOfTheOwnProductAndDomain(): void
    {
        $result = $this->verifier()->verify($this->token(), 'kunde.de');

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame('full', $result['type']);
    }

    /**
     * Der Fall, der die Bezahlschranke traegt: ohne den geheimen Schluessel des
     * Herstellers laesst sich die Payload nicht veraendern.
     */
    public function testRejectsATamperedPayload(): void
    {
        [$payload, $signature] = explode('.', $this->token());
        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        $decoded['expires_at'] = time() + 10 * 365 * 86400;
        $forged = $this->b64url((string) json_encode($decoded)).'.'.$signature;

        $this->assertSame('bad_signature', $this->verifier()->verify($forged, 'kunde.de')['reason']);
    }

    public function testRejectsAForeignKey(): void
    {
        $foreign = sodium_crypto_sign_keypair();
        $token = $this->token(['product' => LicenseToken::PRODUCT], base64_encode(sodium_crypto_sign_secretkey($foreign)));

        $this->assertSame('bad_signature', $this->verifier()->verify($token, 'kunde.de')['reason']);
    }

    public function testRejectsAnotherProduct(): void
    {
        $token = $this->token(['product' => 'netzhirsch/contao-mcp-bundle']);

        $this->assertSame('wrong_product', $this->verifier()->verify($token, 'kunde.de')['reason']);
    }

    public function testRejectsAnotherDomain(): void
    {
        $this->assertSame('wrong_domain', $this->verifier()->verify($this->token(), 'andere-domain.de')['reason']);
    }

    /**
     * Gate und Erneuerung muessen denselben Host sehen, sonst scheitert eine auf
     * "kunde.de" ausgestellte Lizenz am Request-Host "www.kunde.de:8443".
     */
    #[DataProvider('equivalentHostProvider')]
    public function testNormalisesTheHostBeforeComparing(string $host): void
    {
        $this->assertTrue($this->verifier()->verify($this->token(), $host)['valid']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentHostProvider(): iterable
    {
        yield 'wie ausgestellt' => ['kunde.de'];
        yield 'mit www' => ['www.kunde.de'];
        yield 'mit Port' => ['kunde.de:8443'];
        yield 'gross geschrieben' => ['KUNDE.DE'];
        yield 'mit Leerzeichen' => [' kunde.de '];
    }

    public function testRejectsAnExpiredToken(): void
    {
        $token = $this->token(['issued_at' => time() - 7200, 'expires_at' => time() - 3600]);

        $this->assertSame('expired', $this->verifier()->verify($token, 'kunde.de')['reason']);
    }

    /**
     * Ohne High-Water-Mark verlaengert ein Zurueckstellen der Systemuhr die Lizenz.
     * Geprueft wird deshalb gegen den hoechsten je gesehenen Zeitstempel.
     */
    public function testTheHighWaterMarkOutweighsARolledBackClock(): void
    {
        $token = $this->token(['issued_at' => time() - 60, 'expires_at' => time() + 3600]);
        $result = $this->verifier()->verify($token, 'kunde.de', time() + 7200);

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
    }

    public function testToleratesASlightlyLaggingClock(): void
    {
        $token = $this->token(['issued_at' => time() + 120, 'expires_at' => time() + 3600]);

        $this->assertTrue($this->verifier()->verify($token, 'kunde.de')['valid']);
    }

    public function testRejectsATokenFromTheFuture(): void
    {
        $token = $this->token(['issued_at' => time() + 86400, 'expires_at' => time() + 172800]);

        $this->assertSame('clock_tampered', $this->verifier()->verify($token, 'kunde.de')['reason']);
    }

    #[DataProvider('unusableTokenProvider')]
    public function testRejectsUnusableTokens(string $token, string $reason): void
    {
        $this->assertSame($reason, $this->verifier()->verify($token, 'kunde.de')['reason']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableTokenProvider(): iterable
    {
        yield 'leer' => ['', 'no_token'];
        yield 'nur Leerzeichen' => ['   ', 'no_token'];
        yield 'ohne Punkt' => ['abc', 'malformed'];
        yield 'drei Teile' => ['a.b.c', 'malformed'];
        yield 'kein Base64' => ['!!!.!!!', 'malformed'];
    }

    /**
     * Der Server stellt Tokens in genau einer Schreibweise aus. Leerraum mitten im
     * Token laesst base64_decode() auch im strikten Modus durchgehen - inhaltlich
     * waere es dasselbe Token, ausgegeben haben wir aber nur die kanonische Form.
     * Umgebender Leerraum wird weiterhin abgeschnitten, sonst scheitert ein Token,
     * das beim Kopieren einen Zeilenumbruch mitgenommen hat.
     */
    public function testRejectsWhitespaceInsideTheTokenButTrimsAroundIt(): void
    {
        $token = $this->token();
        [$payload, $signature] = explode('.', $token);

        $this->assertTrue(
            $this->verifier()->verify("\n  ".$token."  \t", 'kunde.de')['valid'],
            'Umgebender Leerraum darf ein Token nicht entwerten.',
        );

        foreach ([$payload.' .'.$signature, $payload.'. '.$signature, substr($payload, 0, 5).' '.substr($payload, 5).'.'.$signature] as $mangled) {
            $this->assertSame('malformed', $this->verifier()->verify($mangled, 'kunde.de')['reason']);
        }
    }

    /**
     * Ohne einkompilierten Schluessel ist die Fassung nicht lizenzpflichtig - das
     * entscheidet allein der Schluessel und keine Konfiguration.
     */
    public function testIsArmedOnlyWithARealKey(): void
    {
        $this->assertFalse((new LicenseToken(''))->isArmed());
        $this->assertFalse((new LicenseToken('zu-kurz'))->isArmed());
        $this->assertTrue($this->verifier()->isArmed());
    }

    /**
     * Der Produkt-Slug muss dem Paketnamen entsprechen: unter diesem Namen ist das
     * Produkt auf dem Lizenzserver angelegt, und ein Tippfehler wuerde jede Lizenz
     * als wrong_product verwerfen.
     */
    public function testTheProductSlugMatchesThePackageName(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertSame(LicenseToken::PRODUCT, $composer['name']);
    }

    /**
     * Faengt einen verstuemmelt eingesetzten Herstellerschluessel ab: entweder ist
     * keiner hinterlegt (nicht lizenzpflichtig) oder es sind genau die 32 Bytes eines
     * Ed25519-Public-Keys. Alles dazwischen wuerde jede Lizenz als malformed
     * verwerfen - und das erst beim Kunden.
     *
     * Der Wert kommt ueber constant(), damit die Pruefung beide Faelle wirklich
     * abdeckt: bei einer Konstanten im Quelltext haelt die statische Analyse den
     * jeweils anderen Zweig fuer unerreichbar und der Test verliert genau dann seinen
     * Sinn, wenn der echte Schluessel eingesetzt wird.
     */
    public function testTheShippedKeyIsEitherAbsentOrValid(): void
    {
        $key = (string) \constant(LicenseToken::class.'::VENDOR_PUBLIC_KEY_B64');

        if ('' === $key) {
            $this->assertFalse((new LicenseToken())->isArmed(), 'Ohne Schluessel darf nicht durchgesetzt werden.');

            return;
        }

        $decoded = base64_decode($key, true);

        $this->assertNotFalse($decoded, 'Der einkompilierte Schluessel ist kein Base64.');
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, \strlen($decoded));
        $this->assertTrue((new LicenseToken())->isArmed());
    }

    public function testPeekDomainReadsTheClaimWithoutVerifying(): void
    {
        $foreign = sodium_crypto_sign_keypair();
        $token = $this->token([], base64_encode(sodium_crypto_sign_secretkey($foreign)));

        $this->assertSame('kunde.de', LicenseToken::peekDomain($token));
        $this->assertSame('', LicenseToken::peekDomain('kaputt'));
    }

    #[DataProvider('domainProvider')]
    public function testResolveDomainPrefersTheConfiguredBackendUrl(string $backendUrl, string|null $requestHost, string $expected): void
    {
        $this->assertSame($expected, LicenseToken::resolveDomain($backendUrl, $requestHost));
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function domainProvider(): iterable
    {
        yield 'vollstaendige Adresse' => ['https://www.kunde.de/contao', 'intern.local', 'kunde.de'];
        yield 'nackter Hostname' => ['kunde.de', 'intern.local', 'kunde.de'];
        yield 'ohne Konfiguration' => ['', 'www.kunde.de:8080', 'kunde.de'];
        yield 'ohne alles' => ['', null, ''];
    }

    private function verifier(): LicenseToken
    {
        return new LicenseToken($this->publicKey);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = [], string|null $secretKey = null): string
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
        $secret = (string) base64_decode($secretKey ?? $this->secretKey, true);

        return $this->b64url($json).'.'.$this->b64url(sodium_crypto_sign_detached($json, $secret));
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
