<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Twig;

use Contao\CoreBundle\Image\Studio\Figure;
use Contao\Image\Image;
use Imagine\Image\ImagineInterface;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Stellt die Textfassung der Kennzeichnung im Template bereit.
 *
 * Die eingebrannte Kennzeichnung ist fuer Screenreader nicht wahrnehmbar, und in sehr
 * kleinen Bildgroessen wird sie bewusst weggelassen. Beides macht eine Textalternative
 * im Markup notwendig - der AI Act verlangt eine barrierefreie Kennzeichnung.
 */
final class AiTagExtension extends AbstractExtension
{
    public const PLACEMENT_ALT = 'alt';

    public const PLACEMENT_CAPTION = 'caption';

    public const PLACEMENT_BOTH = 'both';

    public const PLACEMENT_NONE = 'none';

    public const PLACEMENTS = [self::PLACEMENT_ALT, self::PLACEMENT_CAPTION, self::PLACEMENT_BOTH, self::PLACEMENT_NONE];

    public function __construct(
        private readonly AiTagResolver $resolver,
        private readonly ImagineInterface $imagine,
        private readonly string $placement = self::PLACEMENT_ALT,
        private readonly string $separator = ' – ',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('netzhirsch_ai_tag_hint', $this->getHint(...)),
            new TwigFunction('netzhirsch_ai_tag_hint_config', $this->getHintConfig(...)),
        ];
    }

    /**
     * Gibt den Kennzeichnungstext zurueck, wenn das Bild als KI-generiert markiert
     * ist - sonst null.
     */
    public function getHint(Figure|string|null $figure): string|null
    {
        if (null === $figure) {
            return null;
        }

        $path = $figure instanceof Figure ? $figure->getImage()->getFilePath(true) : $figure;

        if ('' === $path) {
            return null;
        }

        try {
            $tag = $this->resolver->resolve(new Image($path, $this->imagine), 80);
        } catch (\Throwable) {
            return null;
        }

        return $tag?->text;
    }

    /**
     * Wo die Textfassung landen soll und womit sie angefuegt wird. Eigene Funktion
     * statt eines erweiterten Rueckgabewerts von getHint(), damit Projekte, die den
     * Text bereits im Template verwenden, nichts anpassen muessen.
     *
     * @return array{placement: string, separator: string}
     */
    public function getHintConfig(): array
    {
        return [
            'placement' => $this->placement,
            'separator' => $this->separator,
        ];
    }
}
