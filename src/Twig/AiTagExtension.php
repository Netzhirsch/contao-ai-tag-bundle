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
    public function __construct(
        private readonly AiTagResolver $resolver,
        private readonly ImagineInterface $imagine,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('netzhirsch_ai_tag_hint', $this->getHint(...)),
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
}
