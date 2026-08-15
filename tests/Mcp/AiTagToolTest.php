<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\Mcp;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\Image\Metadata\MetadataReaderWriter;
use Doctrine\DBAL\Connection;
use Imagine\Image\ImagineInterface;
use Netzhirsch\ContaoAiTagBundle\Audit\AiTagAuditLogger;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use Netzhirsch\ContaoAiTagBundle\Image\FontLocator;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use Netzhirsch\ContaoAiTagBundle\Mcp\AiTagTool;
use Netzhirsch\ContaoMcpBundle\Server\ExtensionToolRegistrar;
use PhpMcp\Server\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Eingabepruefung laeuft absichtlich vor jedem Datenbank- und Rechtezugriff -
 * deshalb sind diese Faelle ohne verdrahtete MCP-Dienste pruefbar.
 */
class AiTagToolTest extends TestCase
{
    #[DataProvider('invalidPathProvider')]
    public function testRejectsUnsafePaths(string $path, string $expectedError): void
    {
        $result = $this->tool()->get($path);

        $this->assertSame($expectedError, $result['error'] ?? null, (string) ($result['message'] ?? ''));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPathProvider(): iterable
    {
        yield 'leer' => ['', 'invalid_path'];
        yield 'nur Leerzeichen' => ['   ', 'invalid_path'];
        yield 'absolut (unix)' => ['/etc/passwd', 'invalid_path'];
        yield 'absolut (windows)' => ['C:/windows/win.ini', 'invalid_path'];
        yield 'Rueckwaertsnavigation' => ['files/../../.env', 'invalid_path'];
        yield 'ausserhalb des Upload-Verzeichnisses' => ['var/logs/prod.log', 'invalid_path'];
        yield 'Backslashes werden normalisiert und geprueft' => ['..\\..\\secret.txt', 'invalid_path'];
    }

    public function testRejectsUnknownPosition(): void
    {
        $result = $this->tool()->set('files/demo.jpg', true, 'middle');

        $this->assertSame('invalid_input', $result['error'] ?? null);
        $this->assertStringContainsString('position must be one of', (string) $result['message']);
    }

    public function testRejectsOverlongText(): void
    {
        $result = $this->tool()->set('files/demo.jpg', true, null, str_repeat('a', 129));

        $this->assertSame('invalid_input', $result['error'] ?? null);
        $this->assertStringContainsString('128 characters', (string) $result['message']);
    }

    public function testRemovingTheLabelNeedsConfirmation(): void
    {
        $result = $this->tool()->set('files/demo.jpg', false);

        $this->assertSame('destructive_confirmation_required', $result['error'] ?? null);
    }

    public function testRegistersOnlyWhenTheOperatorEnabledIt(): void
    {
        $enabled = new Registry(new NullLogger());
        (new ExtensionToolRegistrar(new NullLogger()))->register($enabled, ['netzhirsch_ai_tag_get'], [AiTagTool::class]);

        $this->assertNotNull($enabled->getTool('netzhirsch_ai_tag_get'));

        $disabled = new Registry(new NullLogger());
        (new ExtensionToolRegistrar(new NullLogger()))->register($disabled, [], [AiTagTool::class]);

        $this->assertNull($disabled->getTool('netzhirsch_ai_tag_get'));
    }

    public function testEveryToolDeclaresItsPermissionRequirement(): void
    {
        $permissions = $this->tool()->getMcpToolPermissions();

        foreach (['netzhirsch_ai_tag_get', 'netzhirsch_ai_tag_list', 'netzhirsch_ai_tag_set'] as $tool) {
            $this->assertArrayHasKey($tool, $permissions, 'Ohne Deklaration waere das Werkzeug nur fuer Administratoren erreichbar.');
            $this->assertSame(['kind' => 'module', 'module' => 'files'], $permissions[$tool]);
        }
    }

    /**
     * Die eigenen Klassen sind final - statt sie aufzuweichen, werden sie echt gebaut
     * und nur ihre Mitarbeiter attrappiert. Keiner von ihnen wird auf den hier
     * geprueften Pfaden beruehrt.
     */
    private function tool(): AiTagTool
    {
        $connection = $this->createStub(Connection::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $style = new TagStyle();

        $resolver = new AiTagResolver(
            $connection,
            new RequestStack(),
            $this->createStub(PageFinder::class),
            $translator,
            new CornerSelector(),
            $style,
            '/var/www/project',
            'files',
        );

        $renderer = new TagRenderer(
            $this->createStub(ImagineInterface::class),
            $this->createStub(MetadataReaderWriter::class),
            new FontLocator(),
            new Filesystem(),
            $style,
        );

        $auditLogger = new AiTagAuditLogger(
            $connection,
            $this->createStub(Security::class),
            $translator,
        );

        return new AiTagTool(
            $connection,
            $this->createStub(ContaoFramework::class),
            $resolver,
            $renderer,
            $auditLogger,
            '/var/www/project',
            'files',
        );
    }
}
