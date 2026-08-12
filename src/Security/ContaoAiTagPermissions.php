<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle\Security;

/**
 * Single Source of Truth fuer die Rechte-Attribute des Bundles.
 */
final class ContaoAiTagPermissions
{
    /**
     * Recht, die KI-Kennzeichnung an Dateien und Ordnern zu setzen oder zu entfernen.
     *
     * Subject: 'flag'
     */
    public const string USER_CAN_FLAG = 'contao_user.netzhirsch_ai_tagp';

    /**
     * Erlaubter Wert des Rechte-Feldes.
     */
    public const string OPERATION_FLAG = 'flag';

    /**
     * Backend-Modul mit dem Kennzeichnungs-Protokoll.
     */
    public const string MODULE_LOG = 'netzhirsch_ai_tag_log';
}
