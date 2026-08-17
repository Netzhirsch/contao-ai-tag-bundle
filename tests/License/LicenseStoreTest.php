<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
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
