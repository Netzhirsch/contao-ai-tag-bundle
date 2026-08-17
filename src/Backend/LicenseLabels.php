<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Backend;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Loest alle Texte der Lizenz-Seite auf, bevor die Vorlage sie bekommt.
 *
 * Warum nicht einfach den Uebersetzer oder eine Closure in die Vorlage geben:
 * Contaos Template::__get() ruft jedes aufrufbare Objekt beim Lesen sofort auf
 * (`return $this->arrData[$strKey]();`). Eine uebergebene Closure wird dadurch
 * ohne Argumente ausgefuehrt und die Seite bricht mit "Too few arguments" ab.
 * Deshalb bekommt die Vorlage ausschliesslich Zeichenketten - auch der Grund und
 * die Resttage sind hier schon eingesetzt.
 */
final class LicenseLabels
{
    private const TRANSLATION_DOMAIN = 'netzhirsch_ai_tag';

    private const PREFIX = 'netzhirsch_ai_tag.license.';

    /**
     * Feste Texte der Seite. Die Liste ist die Schnittstelle zur Vorlage: was hier
     * fehlt, ist dort leer.
     */
    private const KEYS = [
        'headline',
        'field.state',
        'field.type',
        'field.expiry',
        'field.domain',
        'field.file',
        'state.active',
        'state.inactive',
        'state.grace',
        'state.not_enforced',
        'type.trial',
        'type.full',
        'type.internal',
        'expiry.unlimited',
        'button.trial',
        'button.subscribe',
        'button.manage',
        'button.refresh',
        'help.trial',
        'help.subscribe',
        'help.manage',
        'help.refresh',
        'help.scope',
        'help.file',
        'notice.not_enforced',
        'notice.inactive',
        'notice.grace',
    ];

    /**
     * @param array{reason: string, days_left: int} $state Lizenzzustand aus dem LicenseGate
     *
     * @return array<string, string>
     */
    public static function build(TranslatorInterface $translator, array $state): array
    {
        $labels = [];

        foreach (self::KEYS as $key) {
            $labels[$key] = $translator->trans(self::PREFIX.$key, [], self::TRANSLATION_DOMAIN);
        }

        // Zusammengesetzt, damit in der Vorlage kein Uebersetzeraufruf mehr steht.
        $labels['reason'] = $translator->trans(self::PREFIX.'reason.'.$state['reason'], [], self::TRANSLATION_DOMAIN);
        $labels['days_left'] = $translator->trans(
            self::PREFIX.'expiry.days_left',
            ['%days%' => (string) $state['days_left']],
            self::TRANSLATION_DOMAIN,
        );

        return $labels;
    }
}
