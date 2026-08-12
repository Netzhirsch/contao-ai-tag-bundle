<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Netzhirsch\ContaoAiTagBundle\Security\ContaoAiTagPermissions;

/*
 * Dasselbe Recht auch direkt am Benutzer (Palette 'extend' und 'custom'), damit
 * Einzelrechte ohne Gruppe vergeben werden koennen - wie im Core.
 */

$GLOBALS['TL_DCA']['tl_user']['fields']['netzhirsch_ai_tagp'] = [
    'inputType' => 'checkbox',
    'options' => [ContaoAiTagPermissions::OPERATION_FLAG],
    'reference' => &$GLOBALS['TL_LANG']['tl_user_group']['netzhirsch_ai_tagpRef'],
    'eval' => ['multiple' => true],
    'sql' => ['type' => 'blob', 'notnull' => false],
];

$netzhirschAiTagUserPalette = PaletteManipulator::create()
    ->addLegend('netzhirsch_ai_tag_legend', 'filemounts_legend', PaletteManipulator::POSITION_AFTER)
    ->addField('netzhirsch_ai_tagp', 'netzhirsch_ai_tag_legend', PaletteManipulator::POSITION_APPEND)
;

foreach (['extend', 'custom'] as $netzhirschAiTagUserPaletteName) {
    if (isset($GLOBALS['TL_DCA']['tl_user']['palettes'][$netzhirschAiTagUserPaletteName])) {
        $netzhirschAiTagUserPalette->applyToPalette($netzhirschAiTagUserPaletteName, 'tl_user');
    }
}

unset($netzhirschAiTagUserPalette, $netzhirschAiTagUserPaletteName);
