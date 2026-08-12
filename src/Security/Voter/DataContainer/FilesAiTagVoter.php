<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Security\Voter\DataContainer;

use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\CoreBundle\Security\Voter\DataContainer\AbstractDataContainerVoter;
use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Schuetzt die KI-Kennzeichnung in der Dateiverwaltung.
 *
 * Zweite Verteidigungslinie neben 'exclude' => true in der DCA: die
 * Feldrechte-Pruefung greift nur im Backend-Formular, dieser Voter auch bei
 * Schreibzugriffen ueber die DC-API, Console-Commands oder MCP-Werkzeuge.
 *
 * Die Basisklasse liefert ausschliesslich ACCESS_DENIED oder ACCESS_ABSTAIN,
 * niemals ACCESS_GRANTED - der Voter kann die Dateimount-Pruefungen des Cores
 * also nicht versehentlich aushebeln.
 */
class FilesAiTagVoter extends AbstractDataContainerVoter
{
    private const GUARDED_FIELDS = [
        'netzhirschAiGenerated',
        'netzhirschAiTagPosition',
        'netzhirschAiTagText',
    ];

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function getTable(): string
    {
        return 'tl_files';
    }

    protected function hasAccess(TokenInterface $token, CreateAction|DeleteAction|ReadAction|UpdateAction $action): bool
    {
        if (!$action instanceof UpdateAction && !$action instanceof CreateAction) {
            return true;
        }

        $new = $action->getNew();

        if (null === $new || !$this->touchesGuardedField($action, $new)) {
            return true;
        }

        return $this->accessDecisionManager->decide(
            $token,
            [ContaoAiTagPermissions::USER_CAN_FLAG],
            ContaoAiTagPermissions::OPERATION_FLAG,
        );
    }

    /**
     * @param array<string, mixed> $new
     */
    private function touchesGuardedField(CreateAction|UpdateAction $action, array $new): bool
    {
        $current = $action instanceof UpdateAction ? $action->getCurrent() : [];

        foreach (self::GUARDED_FIELDS as $field) {
            if (!\array_key_exists($field, $new)) {
                continue;
            }

            if (($current[$field] ?? null) !== $new[$field]) {
                return true;
            }
        }

        return false;
    }
}
