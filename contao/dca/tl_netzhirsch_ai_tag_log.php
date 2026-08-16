<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;

/*
 * Nachweis-Protokoll der KI-Kennzeichnung.
 *
 * Bewusst nur lesbar: 'closed', 'notEditable' und 'notDeletable' reduzieren die
 * von Contao generierten Operationen automatisch auf "Anzeigen" - eigene
 * list.operations sind deshalb nicht noetig. Der AiTagLogVoter sperrt zusaetzlich
 * Schreibzugriffe unterhalb der Oberflaeche.
 */

$GLOBALS['TL_DCA']['tl_netzhirsch_ai_tag_log'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'notDeletable' => true,
        'enableVersioning' => false,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'filePath' => 'index',
                'tstamp' => 'index',
            ],
        ],
    ],
    'list' => [
        'global_operations' => [
            // Ziel und Anfrage-Token setzt der AiTagLogExportButtonListener; ein
            // fest verdrahteter href wuerde relativ zur aktuellen Backend-URL
            // aufgeloest und traege kein Token.
            'netzhirschAiTagExport' => [
                'href' => '',
                'class' => 'header_icon',
                'icon' => 'theme_export.svg',
            ],
        ],
        'sorting' => [
            'mode' => DataContainer::MODE_SORTED,
            'fields' => ['tstamp DESC'],
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'filePath',
        ],
        'label' => [
            'fields' => ['tstamp', 'action', 'filePath', 'username'],
            'showColumns' => true,
            'format' => '%s – %s – %s (%s)',
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'tstamp' => [
            'flag' => DataContainer::SORT_DAY_DESC,
            'sorting' => true,
            'eval' => ['rgxp' => 'datim'],
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'action' => [
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_netzhirsch_ai_tag_log']['actionRef'],
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'scope' => [
            'filter' => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_netzhirsch_ai_tag_log']['scopeRef'],
            'sql' => ['type' => 'string', 'length' => 16, 'default' => 'file'],
        ],
        'filePath' => [
            'search' => true,
            'sorting' => true,
            'sql' => ['type' => 'string', 'length' => 1022, 'default' => ''],
        ],
        'detail' => [
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'userId' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'username' => [
            'filter' => true,
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
    ],
];
