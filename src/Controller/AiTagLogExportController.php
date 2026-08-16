<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Controller;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Netzhirsch\ContaoAiTagBundle\Export\AiTagLogExporter;
use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Gibt das Kennzeichnungs-Protokoll als CSV aus.
 *
 * Der Export enthaelt Dateipfade und Benutzernamen, also personenbezogene Daten -
 * deshalb dieselbe Zugriffspruefung wie das Backend-Modul, ein Token gegen
 * untergeschobene Aufrufe und die Moeglichkeit, ohne Namen zu exportieren.
 */
class AiTagLogExportController
{
    public function __construct(
        private readonly AiTagLogExporter $exporter,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $csrfTokenName,
    ) {
    }

    #[Route(
        path: '/contao/netzhirsch/ai-tag/export',
        name: 'netzhirsch_ai_tag_log_export',
        methods: ['GET'],
        defaults: ['_scope' => 'backend', '_token_check' => false],
    )]
    public function __invoke(Request $request): Response
    {
        // Wer das Protokoll nicht sehen darf, darf es auch nicht exportieren.
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, ContaoAiTagPermissions::MODULE_LOG)) {
            throw new AccessDeniedHttpException('Kein Zugriff auf das Kennzeichnungs-Protokoll.');
        }

        // Eigene Pruefung statt _token_check: so scheitert ein untergeschobener Link
        // eindeutig hier und nicht irgendwo in der Contao-Kette.
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $request->query->get('rt')))) {
            throw new AccessDeniedHttpException('Ungueltiges Anfrage-Token.');
        }

        $moment = $this->moment($request);
        $withUsernames = '1' !== (string) $request->query->get('anonymous');

        $response = new StreamedResponse(
            function () use ($moment, $withUsernames): void {
                // Ohne BOM zerlegt Excel die Umlaute
                echo "\xEF\xBB\xBF";

                $lines = null === $moment
                    ? $this->exporter->streamLog($withUsernames)
                    : $this->exporter->streamStateAt($moment, $withUsernames);

                foreach ($lines as $line) {
                    echo $line;
                    flush();
                }
            },
        );

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->exporter->filename($moment)),
        );

        return $response;
    }

    private function moment(Request $request): \DateTimeImmutable|null
    {
        $asOf = trim((string) $request->query->get('as_of'));

        if ('' === $asOf) {
            return null;
        }

        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);

        if (false === $moment) {
            throw new BadRequestHttpException('as_of erwartet ein Datum im Format JJJJ-MM-TT.');
        }

        // Der Stichtag umfasst den ganzen Tag
        return $moment->setTime(23, 59, 59);
    }
}
