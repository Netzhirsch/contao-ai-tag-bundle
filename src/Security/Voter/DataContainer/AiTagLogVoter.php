<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Security\Voter\DataContainer;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Haelt das Kennzeichnungs-Protokoll unveraenderlich.
 *
 * Ein Nachweis, der nachtraeglich editiert werden kann, ist als Nachweis wertlos:
 * Anlegen und Aendern sind ueber die Oberflaeche generell gesperrt, Loeschen nur
 * fuer Administratoren (Aufbewahrungsfristen). Die Eintraege selbst schreibt das
 * Bundle per Doctrine, nicht ueber den DataContainer.
 */
class AiTagLogVoter extends AbstractDataContainerVoter
{
    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function getTable(): string
    {
        return 'tl_netzhirsch_ai_tag_log';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        return match (true) {
            $action instanceof CreateAction, $action instanceof UpdateAction => false,
            $action instanceof DeleteAction => $this->accessDecisionManager->decide($token, ['ROLE_ADMIN']),
            default => true,
        };
    }
}
