<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Image\ImageFactoryInterface;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\DataContainer;
use Contao\DC_Folder;
use Doctrine\DBAL\Connection;
use Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer\AiTagPreviewListener;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\CornerSelector;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\LicenseToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class AiTagPreviewListenerTest extends TestCase
{
    private string|null $projectDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->projectDir) {
            (new Filesystem())->remove($this->projectDir);
        }

        parent::tearDown();
    }

    public function testShowsAHintInsteadOfPreviewsForFormatsThatCannotBeTagged(): void
    {
        $imageFactory = $this->createMock(ImageFactoryInterface::class);
        $imageFactory
            ->expects($this->never())
            ->method('create')
        ;

        $html = $this->render($imageFactory, 'logo.svg');

        $this->assertStringContainsString('netzhirsch_ai_tag.preview.not_taggable', $html);
        $this->assertStringNotContainsString('<img', $html, 'Fuer SVG gibt es nichts gegenueberzustellen.');
    }

    public function testReturnsNothingForAMissingFile(): void
    {
        $imageFactory = $this->createMock(ImageFactoryInterface::class);
        $imageFactory
            ->expects($this->never())
            ->method('create')
        ;

        $this->assertSame('', $this->render($imageFactory, 'gibtsnicht.jpg', false));
    }

    private function render(ImageFactoryInterface $imageFactory, string $name, bool $createFile = true): string
    {
        $filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/aitag-preview-'.bin2hex(random_bytes(4));
        $filesystem->mkdir($this->projectDir.'/files');

        if ($createFile) {
            $filesystem->dumpFile($this->projectDir.'/files/'.$name, 'x');
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $gate = new LicenseGate(new LicenseToken(''), new LicenseStore($this->projectDir), new RequestStack());

        $resolver = new AiTagResolver(
            $this->createStub(Connection::class),
            new RequestStack(),
            $this->createStub(PageFinder::class),
            $translator,
            new CornerSelector(),
            new TagStyle(),
            $this->projectDir,
            'files',
        );

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://example.com/contao'));

        $listener = new AiTagPreviewListener($imageFactory, $resolver, $gate, $translator, $requestStack, $this->projectDir, []);

        return $listener($this->dataContainer('files/'.$name));
    }

    private function dataContainer(string $id): DataContainer
    {
        // Der Listener liest ausschliesslich $dc->id; ein echter DC_Folder wuerde eine
        // vollstaendige Contao-Umgebung erwarten.
        $dc = (new \ReflectionClass(DC_Folder::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}
