<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Image\ImageFactoryInterface;
use Contao\DataContainer;
use Contao\Image\ResizeConfiguration;
use Contao\Image\ResizeOptions;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResizer;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stellt in der Dateibearbeitung beide Fassungen nebeneinander: das Bild ohne und
 * mit eingebrannter Kennzeichnung.
 *
 * Backend-Bilder werden sonst nicht gekennzeichnet (siehe AiTagResizer), sonst
 * traege schon das Thumbnail in der Dateiliste das Label. Fuer die rechte Fassung
 * wird die Kennzeichnung deshalb gezielt erzwungen.
 */
#[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiTagPreview.input_field')]
class AiTagPreviewListener
{
    private const PREVIEW_WIDTH = 340;

    private const PREVIEW_HEIGHT = 255;

    /**
     * @param array<string, mixed> $imagineOptions
     */
    public function __construct(
        private readonly ImageFactoryInterface $imageFactory,
        private readonly AiTagResolver $resolver,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly string $projectDir,
        private readonly array $imagineOptions,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $path = (string) $dc->id;
        $absolutePath = $this->projectDir.'/'.$path;

        if (!is_file($absolutePath)) {
            return '';
        }

        if (!$this->resolver->isTaggableFormat($path)) {
            return $this->widget($this->translator->trans('netzhirsch_ai_tag.preview.not_taggable', [], 'netzhirsch_ai_tag'), '');
        }

        try {
            $plain = $this->url($absolutePath, false);
            $tagged = $this->url($absolutePath, true);
        } catch (\Throwable) {
            return '';
        }

        $body = \sprintf(
            '<div style="display:flex;flex-wrap:wrap;gap:1rem">%s%s</div>',
            $this->figure($plain, $this->translator->trans('netzhirsch_ai_tag.preview.plain', [], 'netzhirsch_ai_tag')),
            $this->figure($tagged, $this->translator->trans('netzhirsch_ai_tag.preview.tagged', [], 'netzhirsch_ai_tag')),
        );

        return $this->widget($this->translator->trans('netzhirsch_ai_tag.preview.hint', [], 'netzhirsch_ai_tag'), $body);
    }

    private function url(string $absolutePath, bool $forceTag): string
    {
        $configuration = (new ResizeConfiguration())
            ->setWidth(self::PREVIEW_WIDTH)
            ->setHeight(self::PREVIEW_HEIGHT)
            ->setMode(ResizeConfiguration::MODE_BOX)
        ;

        $imagineOptions = $this->imagineOptions;

        if ($forceTag) {
            $imagineOptions[AiTagResizer::FORCE_KEY] = true;
        }

        // Die Groesse muss als ResizeConfiguration uebergeben werden: bei einem Array
        // baut die ImageFactory die Optionen selbst und verwirft die uebergebenen -
        // damit ginge die erzwungene Kennzeichnung verloren.
        $url = rawurldecode(
            $this->imageFactory
                ->create($absolutePath, $configuration, (new ResizeOptions())->setImagineOptions($imagineOptions))
                ->getUrl($this->projectDir),
        );

        // getUrl() liefert einen projektrelativen Pfad ohne fuehrenden Schraegstrich.
        // Ohne den Basispfad zeigt er in einer Unterverzeichnis-Installation ins Leere.
        return $this->requestStack->getCurrentRequest()?->getBasePath().'/'.ltrim($url, '/');
    }

    private function figure(string $url, string $caption): string
    {
        return \sprintf(
            '<figure style="margin:0"><img src="%s" alt="" style="max-width:100%%;height:auto;border:1px solid var(--form-border,#bbb)"><figcaption style="margin-top:.25rem">%s</figcaption></figure>',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($caption, ENT_QUOTES),
        );
    }

    private function widget(string $hint, string $body): string
    {
        return \sprintf(
            '<div class="widget"><h3><label>%s</label></h3>%s<p class="tl_help tl_tip">%s</p></div>',
            htmlspecialchars($this->translator->trans('netzhirsch_ai_tag.preview.label', [], 'netzhirsch_ai_tag'), ENT_QUOTES),
            $body,
            htmlspecialchars($hint, ENT_QUOTES),
        );
    }
}
