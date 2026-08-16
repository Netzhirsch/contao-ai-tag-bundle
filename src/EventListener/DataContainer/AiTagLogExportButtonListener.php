<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Baut die Schaltflaeche fuer den CSV-Export im Protokoll-Modul.
 *
 * Eigener Callback statt eines festen href in der DCA: der Link zeigt auf eine eigene
 * Route und muss das Anfrage-Token tragen, sonst weist der Controller ihn ab.
 */
#[AsCallback(table: 'tl_netzhirsch_ai_tag_log', target: 'list.global_operations.netzhirschAiTagExport.button')]
class AiTagLogExportButtonListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly string $csrfTokenName,
    ) {
    }

    public function __invoke(string|null $href, string $label, string $title, string $class, string $attributes): string
    {
        $url = $this->urlGenerator->generate(
            'netzhirsch_ai_tag_log_export',
            ['rt' => $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue()],
        );

        $label = $this->translator->trans('netzhirsch_ai_tag.export.button', [], 'netzhirsch_ai_tag');

        return \sprintf(
            '<a href="%s" class="%s" title="%s" download>%s</a> ',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($class, ENT_QUOTES),
            htmlspecialchars($this->translator->trans('netzhirsch_ai_tag.export.title', [], 'netzhirsch_ai_tag'), ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES),
        );
    }
}
