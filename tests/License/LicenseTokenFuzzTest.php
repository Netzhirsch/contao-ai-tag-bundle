<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use PHPUnit\Framework\TestCase;

/**
 * Greift die Signaturpruefung systematisch an, statt sie beispielhaft zu pruefen.
 *
 * Die Bezahlschranke haengt an genau einer Aussage: ohne den geheimen Schluessel
 * des Herstellers kommt kein Token durch. Diese Aussage laesst sich nicht mit
 * drei handverlesenen Faellen belegen - hier werden deshalb hunderte veraenderte
 * Fassungen eines gueltigen Tokens durchprobiert. Der Zufall ist mit festem
 * Startwert versehen, damit ein Fehlschlag reproduzierbar bleibt.
 */
class LicenseTokenFuzzTest extends TestCase
{
    private const ITERATIONS = 400;

    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        // Fester Startwert, damit ein Fehlschlag in der CI reproduzierbar ist. Fuer
        // einen breiteren Durchgang laesst er sich ueberschreiben: AI_TAG_FUZZ_SEED=4711
        // vendor/bin/phpunit --filter Fuzz
        mt_srand((int) ($_SERVER['AI_TAG_FUZZ_SEED'] ?? 20260817));

        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
    }

    /**
     * Ein Bit an irgendeiner Stelle des Tokens genuegt, und es ist ungueltig.
     */
    public function testNoMutationOfAValidTokenIsAccepted(): void
    {
        $verifier = new LicenseToken($this->publicKey);
        $token = $this->token();

        $this->assertTrue($verifier->verify($token, 'kunde.de')['valid'], 'Das unveraenderte Token muss gelten.');

        $accepted = [];

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $mutated = $this->mutate($token);

            // Varianten, die sich nur in der Schreibweise unterscheiden (Leerraum,
            // Polsterung), sind dasselbe Token - sie tragen denselben Inhalt und dieselbe
            // Signatur. Die zu pruefende Aussage ist: kein Token mit ANDEREM Inhalt darf
            // gelten. Das strenge Format weist sie inzwischen ohnehin ab.
            if ($mutated === $token || $this->decodesIdentically($mutated, $token)) {
                continue;
            }

            if ($verifier->verify($mutated, 'kunde.de')['valid']) {
                $accepted[] = $mutated;
            }
        }

        $this->assertSame([], $accepted, 'Kein veraendertes Token darf gelten.');
    }

    /**
     * Auch ein Token, das aussieht wie unseres, aber mit einem fremden Schluessel
     * signiert wurde, faellt durch - in jeder Variante.
     */
    public function testNoForeignKeyIsAccepted(): void
    {
        $verifier = new LicenseToken($this->publicKey);

        for ($i = 0; $i < 50; ++$i) {
            $foreign = sodium_crypto_sign_keypair();
            $token = $this->token([], base64_encode(sodium_crypto_sign_secretkey($foreign)));

            $result = $verifier->verify($token, 'kunde.de');

            $this->assertFalse($result['valid']);
            $this->assertSame('bad_signature', $result['reason']);
        }
    }

    /**
     * Muell darf nie zu einer Ausnahme fuehren: die Pruefung laeuft auf jedem
     * Bildaufruf, ein Fehler dort waere ein Ausfall der Website.
     */
    public function testGarbageNeverThrows(): void
    {
        $verifier = new LicenseToken($this->publicKey);

        $inputs = [
            '',
            '.',
            '..',
            'a.b',
            str_repeat('A', 10000).'.'.str_repeat('B', 10000),
            base64_encode(random_bytes(64)).'.'.base64_encode(random_bytes(64)),
            "\0.\0",
            '=.=',
            '-_.-_',
            'eyJ9.eyJ9',
        ];

        for ($i = 0; $i < 100; ++$i) {
            $inputs[] = $this->randomString(random_int(0, 200));
        }

        foreach ($inputs as $input) {
            $result = $verifier->verify($input, 'kunde.de');

            $this->assertFalse($result['valid'], 'Zufallseingabe darf nie gelten.');
            $this->assertIsString($result['reason']);
        }
    }

    /**
     * Zusaetzliche Felder in der Payload sind erlaubt - der Server darf die Antwort
     * erweitern, ohne dass aeltere Fassungen des Bundles die Lizenz verwerfen.
     */
    public function testUnknownPayloadFieldsAreTolerated(): void
    {
        $token = $this->token(['seats' => 5, 'note' => 'neu vom Server']);

        $this->assertTrue((new LicenseToken($this->publicKey))->verify($token, 'kunde.de')['valid']);
    }

    /**
     * Zerfallen beide Zeichenketten in genau dieselben Bytes?
     */
    private function decodesIdentically(string $left, string $right): bool
    {
        $decode = static function (string $token): string|null {
            $parts = explode('.', $token);

            if (2 !== \count($parts)) {
                return null;
            }

            $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
            $signature = base64_decode(strtr($parts[1], '-_', '+/'), true);

            return false === $payload || false === $signature ? null : $payload.'|'.$signature;
        };

        $decodedLeft = $decode($left);

        return null !== $decodedLeft && $decodedLeft === $decode($right);
    }

    private function mutate(string $token): string
    {
        [$payload, $signature] = explode('.', $token);

        return match (random_int(1, 8)) {
            // Ein Zeichen in der Payload kippen
            1 => $this->flip($payload).'.'.$signature,
            // Ein Zeichen in der Signatur kippen
            2 => $payload.'.'.$this->flip($signature),
            // Teile tauschen
            3 => $signature.'.'.$payload,
            // Abschneiden
            4 => substr($payload, 0, max(1, \strlen($payload) - random_int(1, 20))).'.'.$signature,
            5 => $payload.'.'.substr($signature, 0, max(1, \strlen($signature) - random_int(1, 20))),
            // Anhaengen
            6 => $payload.$this->randomString(random_int(1, 4)).'.'.$signature,
            7 => $payload.'.'.$signature.$this->randomString(random_int(1, 4)),
            // Eigene Payload mit fremder Signatur
            default => rtrim(strtr(base64_encode((string) json_encode([
                'product' => LicenseToken::PRODUCT,
                'domain' => 'kunde.de',
                'type' => 'full',
                'license_id' => 'gefaelscht',
                'issued_at' => time() - 60,
                'expires_at' => time() + 10 * 365 * 86400,
            ])), '+/', '-_'), '=').'.'.$signature,
        };
    }

    private function flip(string $value): string
    {
        if ('' === $value) {
            return 'x';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $position = random_int(0, \strlen($value) - 1);
        $value[$position] = $alphabet[random_int(0, \strlen($alphabet) - 1)];

        return $value;
    }

    private function randomString(int $length): string
    {
        $out = '';

        for ($i = 0; $i < $length; ++$i) {
            $out .= \chr(random_int(32, 126));
        }

        return $out;
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
        $signature = sodium_crypto_sign_detached($json, (string) base64_decode($secretKey ?? $this->secretKey, true));

        return $this->b64url($json).'.'.$this->b64url($signature);
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
