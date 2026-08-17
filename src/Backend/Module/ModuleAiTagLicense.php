<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Backend\Module;

use Contao\BackendModule;
use Contao\BackendTemplate;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\Environment;
use Contao\Input;
use Contao\Message;
use Contao\System;
use Netzhirsch\ContaoAiTagBundle\EventListener\BillingReturnListener;
use Netzhirsch\ContaoAiTagBundle\License\LicenseGate;
use Netzhirsch\ContaoAiTagBundle\License\LicenseStore;
use Netzhirsch\ContaoAiTagBundle\License\RenewalClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backend-Modul "KI-Kennzeichnung: Lizenz". Zeigt den Zustand und traegt die
 * Schaltflaechen fuer Testphase, Abonnement und Abo-Verwaltung.
 *
 * Nur fuer Administratoren: hier werden kostenpflichtige Abonnements gestartet
 * und verwaltet. Contao behandelt seine vergleichbaren Module (Einstellungen,
 * Wartung) genauso.
 *
 * Aendernde Aktionen laufen vor dem Rendern und enden immer in einer
 * Weiterleitung. POSTs schuetzt Contaos Kernel (REQUEST_TOKEN), die
 * Schaltflaechen sind Links und werden hier gegen den `rt`-Parameter geprueft -
 * genau wie Contao seine eigenen Operations-Links prueft.
 *
 * Die Mitarbeiter kommen aus dem Container, weil Contao BackendModule-Klassen
 * selbst erzeugt und keinen Konstruktor injiziert.
 *
 * @property BackendTemplate $Template Von BackendModule::generate() gesetzt, ueber
 *                                     die magischen Zugriffsmethoden der Basisklasse
 */
class ModuleAiTagLicense extends BackendModule
{
    /**
     * Wie oft die Rueckkehr von Stripe /renew versucht, bevor sie auf "wird
     * automatisch aktiviert" ausweicht (ein Versuch pro Sekunde).
     */
    private const BILLING_RETURN_ATTEMPTS = 3;

    private const TRANSLATION_DOMAIN = 'netzhirsch_ai_tag';

    /**
     * @var string
     */
    protected $strTemplate = 'be_netzhirsch_ai_tag_license';

    protected function compile(): void
    {
        $container = System::getContainer();

        if (!$container->get('security.helper')->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Das Lizenz-Modul der KI-Kennzeichnung ist Administratoren vorbehalten.');
        }

        /** @var TranslatorInterface $translator */
        $translator = $container->get('translator');

        // Rueckkehr von Stripe (BillingReturnListener leitet hierher um).
        $billing = (string) Input::get(BillingReturnListener::PARAMETER);

        if ('' !== $billing) {
            $this->handleBillingReturn($billing, $container->get(RenewalClient::class), $translator);
        }

        $action = $this->resolveAction($container);

        if ('' !== $action) {
            $this->handleAction($action, $container, $translator);
        }

        /** @var LicenseGate $gate */
        $gate = $container->get(LicenseGate::class);

        $this->Template->license = $gate->state();
        // Eine interne Lizenz verlaengert sich unbefristet - "35 Tage" waere dort eine
        // Ablaufangabe, die es nicht gibt.
        $this->Template->licensePlan = $container->get(LicenseStore::class)->getPlan();
        $this->Template->licenseFile = $container->get(LicenseStore::class)->filePath();
        $this->Template->trans = static fn (string $key, array $parameters = []): string => $translator->trans(
            'netzhirsch_ai_tag.license.'.$key,
            $parameters,
            self::TRANSLATION_DOMAIN,
        );
        $this->Template->messages = Message::generate();
        $this->Template->referer = $this->getReferer(true);
        $this->Template->backTitle = $GLOBALS['TL_LANG']['MSC']['backBTTitle'] ?? '';
        $this->Template->backLabel = $GLOBALS['TL_LANG']['MSC']['backBT'] ?? 'Back';
        $this->Template->actionUrl = $this->selfUrl();
    }

    private function handleAction(string $action, ContainerInterface $container, TranslatorInterface $translator): void
    {
        /** @var RenewalClient $client */
        $client = $container->get(RenewalClient::class);

        switch ($action) {
            case 'start_trial':
                $this->handleStartTrial($client, $translator);
                break;

            case 'subscribe':
                $this->handleBillingRedirect($client, true, $translator);
                break;

            case 'manage_billing':
                // Fuer eine interne Lizenz gibt es keinen Stripe-Kunden, der Aufruf koennte nur
                // scheitern. Die Schaltflaeche fehlt dort - das hier faengt einen selbst
                // gebauten Link ab.
                if ('internal' === $container->get(LicenseStore::class)->getPlan()) {
                    Message::addInfo($this->message($translator, 'msg.internal_no_billing'));
                    $this->redirectSelf();
                }

                $this->handleBillingRedirect($client, false, $translator);
                break;

            case 'refresh_license':
                $this->handleRefresh($client, $translator);
                break;

            default:
        }
    }

    /**
     * Holt eine Lizenz, die der Server fuer diese Domain schon haelt - eine interne
     * Lizenz oder ein bereits bezahltes Abonnement.
     *
     * Das ist der Grund, warum beide Schaltflaechen einfach funktionieren: kein Token
     * kopieren, kein Warten auf den stuendlichen Cron und kein sinnloser Checkout
     * fuer eine Instanz, die bereits lizenziert ist.
     *
     * @param bool $paidOnly nur eine bezahlte oder interne Lizenz gilt als "erledigt".
     *                       Die Abo-Schaltflaeche uebergibt true: waehrend nur eine
     *                       Testphase laeuft, will der Kunde kaufen - dann muss der
     *                       Checkout trotzdem aufgehen.
     *
     * @return bool true, wenn der Fall damit erledigt ist
     */
    private function claimExistingLicense(RenewalClient $client, TranslatorInterface $translator, bool $paidOnly = false): bool
    {
        $result = $client->renew(true, RenewalClient::INTERACTIVE_TIMEOUT_SECONDS);

        if ($result['ok'] ?? false) {
            // Eine Testphase ist keine Lizenz, die man "schon hat", wenn man auf Abonnieren
            // klickt - dann weiter zum Checkout.
            if ($paidOnly && 'trial' === ($result['type'] ?? '')) {
                return false;
            }

            Message::addConfirmation($this->message($translator, 'msg.claimed'));

            return true;
        }

        // Eine widerrufene Lizenz darf nicht stillschweigend in einen neuen Kauf fuehren.
        if ('revoked' === ($result['error'] ?? '')) {
            Message::addError($this->message($translator, 'msg.revoked'));

            return true;
        }

        // Die Lizenz dieser Domain gehoert einer anderen Installation. Eine Testphase
        // oder ein zweiter Checkout waeren hier falsch (verbrannte Testphase,
        // Doppelbelastung) - stattdessen den Betreiber informieren.
        if ('instance_mismatch' === ($result['error'] ?? '')) {
            Message::addError($this->message($translator, 'msg.instance_mismatch'));

            return true;
        }

        return false;
    }

    private function handleStartTrial(RenewalClient $client, TranslatorInterface $translator): void
    {
        // Schon berechtigt (etwa durch eine interne Lizenz)? Dann keine Testphase verbrauchen.
        if ($this->claimExistingLicense($client, $translator)) {
            $this->redirectSelf();
        }

        $result = $client->startTrial($this->currentUserEmail());

        if ($result['ok'] ?? false) {
            Message::addConfirmation($this->message($translator, 'msg.trial_started'));
        } else {
            Message::addError($this->message($translator, 'msg.action_failed', ['%reason%' => $this->reasonText($result)]));
        }

        $this->redirectSelf();
    }

    /**
     * Holt eine von Stripe gehostete Adresse (Checkout zum Abschluss, Kundenportal
     * zur Verwaltung) und leitet den Browser dorthin. Karten- und SEPA-Daten werden
     * ausschliesslich auf der Stripe-Seite eingegeben, niemals in Contao. Gefolgt
     * wird nur https.
     */
    private function handleBillingRedirect(RenewalClient $client, bool $checkout, TranslatorInterface $translator): void
    {
        // Ein Abschluss, obwohl der Server fuer diese Domain schon eine Berechtigung
        // haelt, wuerde doppelt kosten - dann lieber die vorhandene Lizenz ziehen.
        if ($checkout && $this->claimExistingLicense($client, $translator, true)) {
            $this->redirectSelf();
        }

        $result = $checkout
            ? $client->checkoutSession($this->currentUserEmail())
            : $client->portalSession();

        $url = (string) ($result['url'] ?? '');

        if (($result['ok'] ?? false) && str_starts_with($url, 'https://')) {
            $this->redirect($url);
        }

        Message::addError($this->message($translator, 'msg.action_failed', ['%reason%' => $this->reasonText($result)]));
        $this->redirectSelf();
    }

    /**
     * "Lizenz aktualisieren": ein erzwungener Erneuerungsversuch. Zieht eine interne
     * oder bezahlte Lizenz sofort, statt auf den stuendlichen Cron zu warten.
     */
    private function handleRefresh(RenewalClient $client, TranslatorInterface $translator): void
    {
        if (!$this->claimExistingLicense($client, $translator)) {
            Message::addInfo($this->message($translator, 'msg.no_license_found'));
        }

        $this->redirectSelf();
    }

    /**
     * Behandelt die Rueckkehr von Stripe. Bei Erfolg wird das frisch ausgestellte
     * Token geholt, damit der Zustand sofort umspringt (bestmoeglich - SEPA wird
     * verzoegert bestaetigt, dann holt der stuendliche Cron es nach). Am Ende immer
     * eine saubere Modul-Adresse, damit ein Neuladen die Aktion nicht wiederholt.
     */
    private function handleBillingReturn(string $billing, RenewalClient $client, TranslatorInterface $translator): void
    {
        if ('success' !== $billing) {
            Message::addInfo($this->message($translator, 'msg.billing_cancel'));
            $this->redirectSelf();

            return;
        }

        // Wettlauf mit dem Stripe-Webhook: der Browser ist zurueck, bevor die Zahlung
        // zwingend verarbeitet ist. Kurz wiederholen, damit der haeufige Fall
        // (Kreditkarte) sofort freischaltet statt erst mit dem naechsten Cron.
        for ($attempt = 0; $attempt < self::BILLING_RETURN_ATTEMPTS; ++$attempt) {
            $result = $client->renew(true, RenewalClient::INTERACTIVE_TIMEOUT_SECONDS);

            if ($result['ok'] ?? false) {
                Message::addConfirmation($this->message($translator, 'msg.billing_activated'));
                $this->redirectSelf();

                return;
            }

            // Endgueltige Antworten: ein Wiederholen kann daran nichts aendern, und "wird
            // aktiviert" waere danach schlicht falsch.
            $error = (string) ($result['error'] ?? '');

            if ('revoked' === $error) {
                Message::addError($this->message($translator, 'msg.revoked'));
                $this->redirectSelf();

                return;
            }

            if ('instance_mismatch' === $error) {
                Message::addError($this->message($translator, 'msg.instance_mismatch'));
                $this->redirectSelf();

                return;
            }

            if ($attempt < self::BILLING_RETURN_ATTEMPTS - 1) {
                sleep(1);
            }
        }

        // Noch nicht aktiv - bei SEPA normal, das wird verzoegert bestaetigt. Der
        // stuendliche Cron holt es nach, sobald die Zahlung durch ist.
        Message::addInfo($this->message($translator, 'msg.billing_pending'));
        $this->redirectSelf();
    }

    /**
     * POST-Aktionen kommen vom Kernel bereits CSRF-geprueft. Die Schaltflaechen sind
     * Links und tragen das Token als `rt`; geprueft wird hier genauso wie in Contaos
     * eigenen Backend-Links.
     *
     * Wichtig: Contaos CSRF-Tokens sind je Erzeugung anders maskiert. Ein Vergleich
     * mit getDefaultTokenValue() schlaegt deshalb fast immer fehl und die Aktion
     * verschwaende stillschweigend - immer isTokenValid() benutzen.
     */
    private function resolveAction(ContainerInterface $container): string
    {
        if ('' !== (string) Input::post('FORM_SUBMIT')) {
            return (string) Input::post('action');
        }

        $action = (string) Input::get('action');

        if ('' === $action) {
            return '';
        }

        $requestToken = Input::get('rt');
        $tokenName = (string) $container->getParameter('contao.csrf_token_name');

        if (null === $requestToken || !$container->get('contao.csrf.token_manager')->isTokenValid(new CsrfToken($tokenName, (string) $requestToken))) {
            return '';
        }

        return $action;
    }

    private function redirectSelf(): void
    {
        throw new ResponseException(new RedirectResponse($this->selfUrl()));
    }

    private function selfUrl(): string
    {
        return Environment::get('path').'?do='.(string) Input::get('do').'&rt='.System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();
    }

    private function currentUserEmail(): string
    {
        $user = System::getContainer()->get('security.helper')->getUser();

        // BackendUser gibt `email` ueber __get aus arrData heraus, property_exists()
        // waere hier immer false.
        return $user instanceof BackendUser ? (string) ($user->email ?? '') : '';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function reasonText(array $result): string
    {
        return (string) ($result['message'] ?? $result['error'] ?? '');
    }

    /**
     * @param array<string, string> $parameters
     */
    private function message(TranslatorInterface $translator, string $key, array $parameters = []): string
    {
        return $translator->trans('netzhirsch_ai_tag.license.'.$key, $parameters, self::TRANSLATION_DOMAIN);
    }
}
