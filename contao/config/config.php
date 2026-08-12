<?php

declare(strict_types=1);

use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;

/*
 * Backend-Modul und Rechte. Das Protokoll haengt bewusst in der bestehenden
 * Gruppe "system" - es ist ein Nachweis wie das Systemprotokoll und braucht
 * keine eigene Menuegruppe (und damit kein eigenes Gruppen-Icon).
 */

$GLOBALS['BE_MOD']['system'][ContaoAiTagPermissions::MODULE_LOG] = [
    'tables' => ['tl_netzhirsch_ai_tag_log'],
];

$GLOBALS['TL_PERMISSIONS'][] = 'netzhirsch_ai_tagp';
