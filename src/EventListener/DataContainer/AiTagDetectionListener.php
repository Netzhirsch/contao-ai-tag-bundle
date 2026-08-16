<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceInspector;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceSignal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Zeigt in der Dateibearbeitung, wenn eine Datei sich selbst als KI-generiert ausweist.
 *
 * Steht bewusst in der Hauptpalette und nicht in der Unterpalette: der Hinweis
 * ist gerade dann wichtig, wenn die Kennzeichnung noch NICHT gesetzt ist.
 */
#[AsCallback(table: 'tl_files', target: 'fields.netzhirschAiTagDetection.input_field')]
class AiTagDetectionListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AiSourceInspector $inspector,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT netzhirschAiDetected, netzhirschAiGenerated FROM tl_files WHERE path = ?',
                [(string) $dc->id],
            );
        } catch (DbalException) {
            return '';
        }

        if (false === $row) {
            return '';
        }

        // Sicherheitsnetz: auf Contao 5.3 erreicht die Erkennung nur den Upload im
        // Dateimanager. Wer die Datei hier oeffnet, loest die Pruefung nach.
        $signal = '' === (string) ($row['netzhirschAiDetected'] ?? '')
            ? $this->inspector->inspectIfUnchecked((string) $dc->id)
            : AiSourceSignal::fromStorage((string) $row['netzhirschAiDetected']);

        if (null === $signal) {
            return '';
        }

        $key = $signal->isDeclared() ? 'declared' : 'hint';
        $message = $this->translator->trans(
            'netzhirsch_ai_tag.detection.'.$key,
            ['%source%' => $signal->source, '%detail%' => $signal->detail],
            'netzhirsch_ai_tag',
        );

        if ('1' === (string) ($row['netzhirschAiGenerated'] ?? '')) {
            $message .= ' '.$this->translator->trans('netzhirsch_ai_tag.detection.already_flagged', [], 'netzhirsch_ai_tag');
        } else {
            $message .= ' '.$this->translator->trans('netzhirsch_ai_tag.detection.decide', [], 'netzhirsch_ai_tag');
        }

        return \sprintf(
            '<div class="widget"><p class="tl_%s">%s</p></div>',
            $signal->isDeclared() ? 'new' : 'info',
            htmlspecialchars($message, ENT_QUOTES),
        );
    }
}
