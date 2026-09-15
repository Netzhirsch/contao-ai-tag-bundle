<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\License;

use Netzhirsch\ContaoAiTagBundle\License\UpdateNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpdateNoticeTest extends TestCase
{
    /**
     * Der Normalfall ist "kein Hinweis": ohne Ankuendigung, bei gleicher oder
     * niedrigerer Fassung und auf jeder Entwicklungsinstallation bleibt die Seite so,
     * wie sie ohne diese Meldung aussaehe.
     */
    #[DataProvider('silentProvider')]
    public function testShowsNothing(string $latest, string $installed): void
    {
        $this->assertNull(UpdateNotice::compare($latest, 'https://example.com/notes', false, $installed));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function silentProvider(): iterable
    {
        yield 'keine Ankuendigung' => ['', '1.0.2'];
        yield 'nur Leerraum' => ['   ', '1.0.2'];
        yield 'gleiche Fassung' => ['1.0.2', '1.0.2'];
        yield 'gleiche Fassung mit v' => ['v1.0.2', '1.0.2'];
        yield 'aeltere Fassung' => ['1.0.1', '1.0.2'];

        // Der Grund fuer version_compare: als Zeichenkette waere 1.0.9 groesser als 1.0.10.
        yield 'aeltere Fassung, hoehere Ziffer' => ['1.0.9', '1.0.10'];

        yield 'Entwicklungszweig' => ['1.1.0', 'dev-main'];
        yield 'Entwicklungszweig kurz' => ['1.1.0', 'dev'];
        yield 'Entwicklungsfassung' => ['1.1.0', '1.0.x-dev'];
        yield 'Version unbekannt' => ['1.1.0', ''];
    }

    #[DataProvider('announcedProvider')]
    public function testShowsTheAnnouncedVersion(string $latest, string $installed): void
    {
        $notice = UpdateNotice::compare($latest, 'https://example.com/notes', false, $installed);

        $this->assertNotNull($notice);
        $this->assertSame(trim($latest), $notice['version']);
        $this->assertSame('https://example.com/notes', $notice['url']);
        $this->assertFalse($notice['security']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function announcedProvider(): iterable
    {
        yield 'hoehere Fassung' => ['1.1.0', '1.0.2'];
        yield 'hoehere Ziffer' => ['1.0.10', '1.0.9'];
        yield 'v vorn angekuendigt' => ['v1.1.0', '1.0.2'];
        yield 'v vorn installiert' => ['1.1.0', 'v1.0.2'];
        yield 'Vorabfassung' => ['1.1.0', '1.1.0-beta.1'];
    }

    /**
     * Sicherheitsrelevant ist das Signal, das die Composer-Metadatei nicht traegt -
     * es muss bis in die Anzeige durchkommen.
     */
    public function testPassesTheSecurityFlagThrough(): void
    {
        $notice = UpdateNotice::compare('1.1.0', 'https://example.com/notes', true, '1.0.2');

        $this->assertNotNull($notice);
        $this->assertTrue($notice['security']);
    }

    /**
     * Die Adresse kommt vom eigenen Server, aber ein Link, der ungeprueft ins Markup geht,
     * ist eine schlechte Angewohnheit. Ohne https bleibt der Hinweis, nur ohne Verweis.
     */
    #[DataProvider('rejectedUrlProvider')]
    public function testLinksOnlyHttps(string $url): void
    {
        $notice = UpdateNotice::compare('1.1.0', $url, false, '1.0.2');

        $this->assertNotNull($notice);
        $this->assertSame('', $notice['url']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrlProvider(): iterable
    {
        yield 'leer' => [''];
        yield 'http' => ['http://example.com/notes'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'nur Text' => ['example.com/notes'];
    }
}
