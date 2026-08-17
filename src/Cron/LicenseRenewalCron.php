<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\RenewalClient;
use Psr\Log\LoggerInterface;

/**
 * Haelt das Lizenz-Token frisch - und ist der Weg, auf dem ein Widerruf ankommt.
 *
 * Warum ein Cron: geprueft wird offline, ein regelmaessig erneuertes Token hat
 * also fast immer seine ganze Laufzeit vor sich. Der Lizenzserver darf damit
 * lange nicht erreichbar sein, bevor die dreitaegige Karenz ueberhaupt greift -
 * eine kurze Stoerung bemerkt der Kunde nie.
 *
 * Derselbe Aufruf ist der Widerrufskanal: RenewalClient::renew() loescht das
 * Token, wenn der Server `revoked` antwortet. Auch eine unbefristete interne
 * Lizenz hoert so innerhalb eines Drosselfensters auf zu wirken, waehrend ein
 * reiner Verbindungsfehler von der Karenz aufgefangen wird.
 *
 * Takt: stuendlich, echte Serveraufrufe drosselt der Client auf hoechstens einen alle
 * sechs Stunden - damit bleibt der Cron sanft und die Widerrufsverzoegerung kurz. Auf
 * Seiten mit wenig Verkehr braucht es dafuer einen echten Systemcron (`contao:cron`).
 */
#[AsCronJob('hourly')]
final class LicenseRenewalCron
{
    public function __construct(
        private readonly LicenseStore $store,
        private readonly RenewalClient $renewalClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        // Ohne Token gibt es nichts zu erneuern. Das ist Sache des Gates (no_token ->
        // nicht eingebrannt); der Cron darf fuer Installationen, die nie eine Lizenz
        // aktiviert haben, nicht am Server anklopfen.
        if ('' === $this->store->getToken()) {
            return;
        }

        try {
            $this->renewalClient->renew();
        } catch (\Throwable $exception) {
            // Aus einem Cron-Job darf nie eine Ausnahme herausfliegen, sonst haengt der
            // ganze Cron-Lauf an der Lizenz. Den ausgelassenen Versuch deckt die Karenz.
            $this->logger->warning('Automatische Lizenzerneuerung fehlgeschlagen.', ['exception' => $exception]);
        }
    }
}
