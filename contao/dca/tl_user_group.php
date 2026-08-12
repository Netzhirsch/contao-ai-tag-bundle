<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;

/*
 * Recht, die KI-Kennzeichnung zu setzen. Bewusst ein eigenes Recht und nicht an
 * den Dateimounts haengend: wer Bilder pflegen darf, muss nicht zwingend ueber
 * eine rechtlich verbindliche Kennzeichnung entscheiden duerfen.
 */

$GLOBALS['TL_DCA']['tl_user_group']['fields']['netzhirsch_ai_tagp'] = [
    'inputType' => 'checkbox',
    'options' => [ContaoAiTagPermissions::OPERATION_FLAG],
    'reference' => &$GLOBALS['TL_LANG']['tl_user_group']['netzhirsch_ai_tagpRef'],
    'eval' => ['multiple' => true],
    'sql' => ['type' => 'blob', 'notnull' => false],
];

PaletteManipulator::create()
    ->addLegend('netzhirsch_ai_tag_legend', 'filemounts_legend', PaletteManipulator::POSITION_AFTER)
    ->addField('netzhirsch_ai_tagp', 'netzhirsch_ai_tag_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_user_group')
;
