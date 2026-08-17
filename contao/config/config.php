<?php

declare(strict_types=1);

use Netzhirsch\ContaoAiTagBundle\Backend\Module\ModuleAiTagLicense;
use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;

/*
 * Backend-Module und Rechte. Beide haengen bewusst in der bestehenden Gruppe
 * "system" - das Protokoll ist ein Nachweis wie das Systemprotokoll, die Lizenz
 * ist Betriebsverwaltung. Eine eigene Menuegruppe (und damit ein eigenes
 * Gruppen-Icon) braucht das nicht.
 */

$GLOBALS['BE_MOD']['system'][ContaoAiTagPermissions::MODULE_LOG] = [
    'tables' => ['tl_netzhirsch_ai_tag_log'],
];

$GLOBALS['BE_MOD']['system'][ContaoAiTagPermissions::MODULE_LICENSE] = [
    'callback' => ModuleAiTagLicense::class,
];

$GLOBALS['TL_PERMISSIONS'][] = 'netzhirsch_ai_tagp';
