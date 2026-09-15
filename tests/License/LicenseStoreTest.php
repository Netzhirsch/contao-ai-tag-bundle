<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LicenseStoreTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/ai-tag-license-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0o777, true);
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

    public function testStartsEmpty(): void
    {
        $store = $this->store();

        $this->assertSame('', $store->getToken());
        $this->assertSame('', $store->getInstanceSecret());
        $this->assertSame('', $store->getPlan());
        $this->assertSame(0, $store->getHwm());
        $this->assertSame(0, $store->getLastRenewAt());
        $this->assertSame('', $store->getLatestVersion());
        $this->assertSame('', $store->getReleaseNotesUrl());
        $this->assertFalse($store->isSecurityRelease());
    }

    public function testPersistsAcrossInstances(): void
    {
        $this->store()->setToken('  payload.signature  ');
        $this->store()->setInstanceSecret('geheim');
        $this->store()->setPlan('monthly');

        $this->assertSame('payload.signature', $this->store()->getToken(), 'Randstaendige Leerzeichen wuerden die Signaturpruefung sprengen.');
        $this->assertSame('geheim', $this->store()->getInstanceSecret());
        $this->assertSame('monthly', $this->store()->getPlan());
    }

    /**
     * Das instance_secret gibt der Server nur einmal heraus. Ein leerer Wert aus
     * einer spaeteren Antwort darf es nicht ueberschreiben.
     */
    public function testRefusesToOverwriteTheSecretWithNothing(): void
    {
        $store = $this->store();
        $store->setInstanceSecret('geheim');

        $this->assertFalse($store->setInstanceSecret('   '));
        $this->assertSame('geheim', $store->getInstanceSecret());
    }

    public function testTheHighWaterMarkOnlyMovesForward(): void
    {
        $store = $this->store();
        $now = time();

        $store->bumpHwm($now + 7200);
        $store->bumpHwm($now);

        $this->assertSame($now + 7200, $store->getHwm());
    }

    /**
     * Das Gate ruft bumpHwm() bei jedem Bild auf. Wuerde jede Sekunde geschrieben,
     * waere das sinnlose Last - und jedes Lesen-Aendern-Schreiben ein Fenster, in dem
     * ein parallel erneuertes Token verloren geht.
     */
    public function testTheHighWaterMarkIgnoresSmallAdvances(): void
    {
        $store = $this->store();
        $now = time();

        $store->bumpHwm($now);
        $store->bumpHwm($now + 60);

        $this->assertSame($now, $store->getHwm());
    }

    public function testKeepsAnAnnouncedVersion(): void
    {
        $store = $this->store();

        $this->assertTrue($store->setUpdateNotice('1.1.0', 'https://example.com/notes', true));
        $this->assertSame('1.1.0', $store->getLatestVersion());
        $this->assertSame('https://example.com/notes', $store->getReleaseNotesUrl());
        $this->assertTrue($store->isSecurityRelease());
    }

    /**
     * Eine zurueckgezogene Ankuendigung muss auch wieder verschwinden - sonst haengt
     * der Hinweis auf eine Fassung, die es vielleicht gar nicht mehr gibt.
     */
    public function testAWithdrawnAnnouncementDisappears(): void
    {
        $store = $this->store();
        $store->setUpdateNotice('1.1.0', 'https://example.com/notes', true);

        $store->setUpdateNotice('', '', false);

        $this->assertSame('', $store->getLatestVersion());
        $this->assertSame('', $store->getReleaseNotesUrl());
        $this->assertFalse($store->isSecurityRelease());
    }

    /**
     * Die Werte kommen vom eigenen Server, landen aber im Backend-Markup. Was nicht wie
     * eine Version aussieht oder nicht https ist, wird verworfen statt gespeichert.
     */
    #[DataProvider('rejectedAnnouncementProvider')]
    public function testRejectsUnusableAnnouncements(string $version, string $url, string $expectedVersion, string $expectedUrl): void
    {
        $store = $this->store();
        $store->setUpdateNotice($version, $url, true);

        $this->assertSame($expectedVersion, $store->getLatestVersion());
        $this->assertSame($expectedUrl, $store->getReleaseNotesUrl());
        $this->assertSame('' !== $expectedVersion, $store->isSecurityRelease());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function rejectedAnnouncementProvider(): iterable
    {
        yield 'Markup in der Version' => ['<b>1.1.0</b>', 'https://example.com/notes', '', ''];
        yield 'Leerzeichen in der Version' => ['1.1.0 oder neuer', 'https://example.com/notes', '', ''];
        yield 'zu lange Version' => [str_repeat('1', 33), 'https://example.com/notes', '', ''];
        yield 'Version mit Leerraum aussen' => ['  1.1.0  ', 'https://example.com/notes', '1.1.0', 'https://example.com/notes'];
        yield 'Adresse ohne https' => ['1.1.0', 'http://example.com/notes', '1.1.0', ''];
        yield 'Adresse mit javascript' => ['1.1.0', 'javascript:alert(1)', '1.1.0', ''];
        yield 'Adresse ohne Version' => ['', 'https://example.com/notes', '', ''];
    }

    public function testTheFileLivesUnderVar(): void
    {
        $this->assertStringEndsWith(
            \DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'netzhirsch-ai-tag'.\DIRECTORY_SEPARATOR.'license.json',
            $this->store()->filePath(),
        );
    }

    private function store(): LicenseStore
    {
        return new LicenseStore($this->projectDir);
    }
}
