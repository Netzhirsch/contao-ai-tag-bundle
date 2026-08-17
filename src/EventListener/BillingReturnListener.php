<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

/**
 * Nach Checkout oder Kundenportal schickt Stripe den Kunden zurueck ins Backend. Der
 * Lizenzserver setzt dafuer `https://<domain>/contao?mcp_billing=success|cancel`
 * - also auf die Startseite des Backends, wo unser Modul nie laeuft. Hier wird
 * daraus ein Aufruf des Lizenz-Moduls, das die Meldung zeigen und bei Erfolg das
 * Token nachladen kann.
 *
 * Der Parametername stammt vom Server und ist fuer alle Produkte derselbe (er ist
 * historisch nach dem MCP-Bundle benannt). Deshalb greift dieser Listener nur auf
 * dem nackten Backend-Aufruf ohne `do`: ist bereits ein Modul angefragt - etwa
 * das Lizenz-Modul eines anderen Netzhirsch-Bundles -, bleibt er stehen. Zwei
 * Bundles, die beide auf denselben Parameter umleiten, wuerden sich sonst
 * gegenseitig im Kreis schicken.
 */
final class BillingReturnListener
{
    /**
     * Query-Parameter, den der Lizenzserver an die Rueckkehr-Adresse haengt.
     */
    public const PARAMETER = 'mcp_billing';

    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RouterInterface $router,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $billing = (string) $request->query->get(self::PARAMETER, '');

        if ('' === $billing || !$this->scopeMatcher->isBackendRequest($request)) {
            return;
        }

        if ('' !== (string) $request->query->get('do', '')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('contao_backend', [
            'do' => ContaoAiTagPermissions::MODULE_LICENSE,
            self::PARAMETER => $billing,
        ])));
    }
}
