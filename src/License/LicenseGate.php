<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\License;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Entscheidet, ob die Kennzeichnung gerade lizenziert eingebrannt werden darf.
 *
 * Modell: Testphase -> bezahltes Abo, alles oder nichts. Ein gueltiges, vom
 * Hersteller signiertes Token schaltet das Einbrennen frei; ohne Token bleibt es
 * aus. Contao selbst wird nie angetastet, die Website laeuft weiter.
 *
 * Lizenzpflichtig ist ausdruecklich nur das Einbrennen. Markieren in der
 * Dateiverwaltung, Protokoll, Nachweis-Export und die Textalternative im Markup
 * bleiben frei: ein Kunde darf nie den Zugriff auf seine eigenen Nachweise
 * verlieren, nur weil ein Abo endet. Und weil eine fehlende Kennzeichnung ein
 * rechtliches Risiko ist, sagt das Backend beim Markieren deutlich, dass gerade
 * nichts eingebrannt wird - stillschweigend ausbleiben darf sie nicht.
 *
 * Die Durchsetzung haengt am einkompilierten Public Key: solange keiner
 * hinterlegt ist, ist die Fassung nicht lizenzpflichtig (siehe
 * LicenseToken::isArmed()). Bewusst kein Konfigurationsfeld und keine
 * Umgebungsvariable - beides koennte der Kunde in einer Zeile umlegen. Der Schutz
 * sind die Signatur (ohne den geheimen Schluessel des Herstellers gibt es kein
 * gueltiges Token) und die Aktualisierung ueber den bezahlten Composer-Kanal;
 * Quellcode patchen bleibt moeglich und ueberlebt kein Update.
 */
final class LicenseGate
{
    /**
     * Zusaetzliche Toleranz nach dem Ablauf, um Ausfaelle abzufedern (Sekunden).
     */
    private const GRACE_SECONDS = 3 * 86400;

    /**
     * Ergebnis der letzten Pruefung, gueltig fuer genau dieses Token. Eine Seite mit
     * zwanzig Bildern fragt zwanzig Mal - ohne das Merken waeren das zwanzig
     * Signaturpruefungen. Wechselt das Token (Erneuerung, Widerruf), faellt der
     * Eintrag von selbst weg.
     *
     * @var array{active: bool, armed: bool, type: string, plan: string, reason: string, domain: string, expires_at: int, days_left: int, in_grace: bool}|null
     */
    private array|null $memo = null;

    private string|null $memoToken = null;

    public function __construct(
        private readonly LicenseToken $token,
        private readonly LicenseStore $store,
        private readonly RequestStack $requestStack,
        /**
         * Kanonischer Backend-Host aus der Bundle-Konfiguration. Nur fuer CLI und Cron
         * noetig, wo es keinen Request gibt.
         */
        private readonly string $backendUrl = '',
    ) {
    }

    /**
     * Darf gerade eingebrannt werden? Ohne einkompilierten Schluessel immer ja.
     */
    public function isActive(): bool
    {
        return $this->state()['active'];
    }

    /**
     * Der Lizenzzustand, unabhaengig davon, ob durchgesetzt wird - das Backend soll
     * ihn immer zeigen koennen.
     *
     * @return array{active: bool, armed: bool, type: string, plan: string, reason: string, domain: string, expires_at: int, days_left: int, in_grace: bool}
     */
    public function state(): array
    {
        $storedToken = $this->store->getToken();

        if (null !== $this->memo && $this->memoToken === $storedToken) {
            return $this->memo;
        }

        $this->memoToken = $storedToken;

        return $this->memo = $this->evaluate($storedToken);
    }

    /**
     * Der Host, gegen den geprueft und erneuert wird. Muss in Gate und
     * Erneuerungsclient identisch bestimmt werden.
     */
    public function domain(): string
    {
        $host = LicenseToken::resolveDomain(
            $this->backendUrl,
            $this->requestStack->getCurrentRequest()?->getHost(),
        );

        // In CLI und Cron gibt es keinen Request-Host. Ohne konfigurierte Backend-URL
        // waere die Domain '' und jede Statusabfrage laese sich als `wrong_domain`. Nur
        // DORT auf die Domain des Tokens zurueckfallen - auf dem Auslieferungspfad gibt
        // es immer einen echten Request-Host, die Domainbindung bleibt scharf.
        return '' !== $host ? $host : LicenseToken::peekDomain($this->store->getToken());
    }

    /**
     * @return array{active: bool, armed: bool, type: string, plan: string, reason: string, domain: string, expires_at: int, days_left: int, in_grace: bool}
     */
    private function evaluate(string $storedToken): array
    {
        $plan = $this->store->getPlan();

        if (!$this->token->isArmed()) {
            return [
                'active' => true,
                'armed' => false,
                'type' => '',
                'plan' => $plan,
                'reason' => 'not_enforced',
                'domain' => $this->domain(),
                'expires_at' => 0,
                'days_left' => 0,
                'in_grace' => false,
            ];
        }

        $result = $this->token->verify($storedToken, $this->domain(), $this->store->getHwm());
        $this->store->bumpHwm($result['now_ref']);

        $now = $result['now_ref'];
        $expiresAt = $result['expires_at'];
        $active = $result['valid'];
        $inGrace = false;

        // Karenz: ein gerade abgelaufenes Token gilt noch GRACE_SECONDS weiter, damit
        // eine kurze Server- oder Netzstoerung keinen zahlenden Kunden aussperrt.
        if (!$active && 'expired' === $result['reason'] && $now <= $expiresAt + self::GRACE_SECONDS) {
            $active = true;
            $inGrace = true;
        }

        return [
            'active' => $active,
            'armed' => true,
            'type' => $result['type'],
            'plan' => $plan,
            'reason' => $result['reason'],
            'domain' => $this->domain(),
            'expires_at' => $expiresAt,
            // Nie negativ: innerhalb der Karenz waere "-1 Tage" im Backend Unsinn.
            'days_left' => $expiresAt > 0 ? max(0, (int) ceil(($expiresAt - $now) / 86400)) : 0,
            'in_grace' => $inGrace,
        ];
    }
}
