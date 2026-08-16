<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Image\ImageDimensions;
use Contao\Image\ImportantPart;
use Contao\Image\ResizeCalculator;
use Contao\Image\ResizeConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Imagine\Image\Box;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Netzhirsch\ContaoAiTagBundle\Image\TagRenderer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Ampel: rechnet fuer jede konfigurierte Bildgroesse aus, ob die
 * Kennzeichnung dort noch lesbar ins Bild passt.
 *
 * Damit wird aus "wir kennzeichnen" eine pruefbare Aussage pro Bildgroesse - und
 * die Redaktion sieht, wo nur die Textalternative im Markup traegt.
 */
#[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiTagCoverage.input_field')]
class AiTagCoverageListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AiTagResolver $resolver,
        private readonly TagRenderer $renderer,
        private readonly ResizeCalculator $calculator,
        private readonly TranslatorInterface $translator,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $path = (string) $dc->id;
        $absolutePath = $this->projectDir.'/'.$path;

        if (!is_file($absolutePath) || !$this->resolver->isTaggableFormat($path)) {
            return '';
        }

        $size = @getimagesize($absolutePath);

        if (false === $size) {
            return '';
        }

        $sizes = $this->imageSizes();

        if ([] === $sizes) {
            return '';
        }

        $text = $this->resolver->defaultText();
        $rows = [];

        foreach ($sizes as $imageSize) {
            [$width, $height] = $this->targetDimensions($size[0], $size[1], $imageSize);
            $legible = $this->renderer->isLegible($width, $height, $text);

            $rows[] = \sprintf(
                '<tr><td style="padding:.15rem .75rem .15rem 0">%s</td><td style="padding:.15rem .75rem .15rem 0">%d×%d</td><td style="padding:.15rem 0">%s</td></tr>',
                htmlspecialchars($imageSize['name'], ENT_QUOTES),
                $width,
                $height,
                $legible
                    ? '<span style="color:var(--green,#49840b)">'.htmlspecialchars($this->translator->trans('netzhirsch_ai_tag.coverage.legible', [], 'netzhirsch_ai_tag'), ENT_QUOTES).'</span>'
                    : '<span style="color:var(--red,#c7241f)">'.htmlspecialchars($this->translator->trans('netzhirsch_ai_tag.coverage.too_small', [], 'netzhirsch_ai_tag'), ENT_QUOTES).'</span>',
            );
        }

        return \sprintf(
            '<div class="widget"><h3><label>%s</label></h3><table>%s</table><p class="tl_help tl_tip">%s</p></div>',
            htmlspecialchars($this->translator->trans('netzhirsch_ai_tag.coverage.label', [], 'netzhirsch_ai_tag'), ENT_QUOTES),
            implode('', $rows),
            htmlspecialchars($this->translator->trans('netzhirsch_ai_tag.coverage.hint', [], 'netzhirsch_ai_tag'), ENT_QUOTES),
        );
    }

    /**
     * Rechnet mit Contaos eigenem Rechner, damit die Vorschau dieselben Maszen ergibt
     * wie die spaetere Auslieferung.
     *
     * @param array{name: string, width: int, height: int, mode: string} $imageSize
     *
     * @return array{int, int}
     */
    private function targetDimensions(int $sourceWidth, int $sourceHeight, array $imageSize): array
    {
        $configuration = (new ResizeConfiguration())
            ->setWidth($imageSize['width'])
            ->setHeight($imageSize['height'])
        ;

        if ('' !== $imageSize['mode']) {
            $configuration->setMode($imageSize['mode']);
        }

        $coordinates = $this->calculator->calculate(
            $configuration,
            new ImageDimensions(new Box($sourceWidth, $sourceHeight)),
            new ImportantPart(),
        );

        return [$coordinates->getCropSize()->getWidth(), $coordinates->getCropSize()->getHeight()];
    }

    /**
     * @return list<array{name: string, width: int, height: int, mode: string}>
     */
    private function imageSizes(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT name, width, height, resizeMode FROM tl_image_size WHERE (width > 0 OR height > 0) ORDER BY name',
            );
        } catch (DbalException) {
            return [];
        }

        $sizes = [];

        foreach ($rows as $row) {
            $sizes[] = [
                'name' => (string) $row['name'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'mode' => (string) ($row['resizeMode'] ?? ''),
            ];
        }

        return $sizes;
    }
}
