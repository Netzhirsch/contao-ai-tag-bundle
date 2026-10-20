<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Image;

use Netzhirsch\ContaoAiTagBundle\Image\FontLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Die Schriftsuche hat einmal still versagt: auf einem Server mit open_basedir
 * war die vorhandene Systemschrift fuer PHP nicht lesbar, die Bilder blieben
 * ungekennzeichnet, und der Grund stand nirgends. Deshalb hier die Fehlerpfade,
 * nicht nur der Erfolg.
 */
class FontLocatorTest extends TestCase
{
    public function testUsesTheConfiguredFileWhenItIsReadable(): void
    {
        // Der Locator prueft nur Existenz und Lesbarkeit - eine beliebige Datei genuegt.
        $locator = new FontLocator(null, __FILE__);

        $this->assertSame(__FILE__, $locator->locate());
    }

    public function testAnUnreadableConfiguredFileIsReportedAndNotSilentlyReplaced(): void
    {
        $logger = $this->collectingLogger();
        $locator = new FontLocator($logger, '/gibt/es/nicht.ttf');

        // Eine ausdruecklich konfigurierte Schrift, die fehlt, ist ein Fehler - kein
        // Grund, stillschweigend auf eine Systemschrift auszuweichen, die der Betreiber
        // nicht wollte.
        $this->assertNull($locator->locate());
        $this->assertCount(1, $logger->lines);
        $this->assertStringContainsString('/gibt/es/nicht.ttf', $logger->lines[0]);
    }

    public function testTheResultIsRememberedSoTheErrorIsLoggedOnlyOnce(): void
    {
        $logger = $this->collectingLogger();
        $locator = new FontLocator($logger, '/gibt/es/nicht.ttf');

        $locator->locate();
        $locator->locate();
        $locator->locate();

        $this->assertCount(1, $logger->lines, 'Pro Bild eine Fehlermeldung waere eine Logflut.');
    }

    public function testNamesOpenBasedirAsTheLikelyCauseWhenItIsActive(): void
    {
        $hint = FontLocator::openBasedirHint('/var/www/projekt:/tmp');

        $this->assertStringContainsString('open_basedir', $hint);
        $this->assertStringContainsString('font_path', $hint);
    }

    public function testAddsNothingWhenOpenBasedirIsNotActive(): void
    {
        $this->assertSame('', FontLocator::openBasedirHint(''));
        $this->assertSame('', FontLocator::openBasedirHint('   '));
    }

    /**
     * @return AbstractLogger&object{lines: list<string>}
     */
    private function collectingLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $lines = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }
}
