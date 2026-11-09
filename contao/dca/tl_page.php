<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

/*
 * Wortlaut der Kennzeichnung je Website-Startpunkt.
 *
 * Der Startpunkt ist in Contao pro Sprache vorhanden, damit ist das Feld
 * automatisch sprachspezifisch. Bleibt es leer, greift die gesetzliche
 * Standardformulierung aus den Bundle-Uebersetzungen.
 */

$GLOBALS['TL_DCA']['tl_page']['fields']['netzhirschAiTagText'] = [
    'exclude' => true,
    'inputType' => 'text',
    'eval' => ['maxlength' => 128, 'tl_class' => 'long', 'decodeEntities' => true],
    'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
];

// Eigener Wortlaut fuer "mit KI bearbeitet": der Wortlaut fuer "generiert" darf dort nicht
// stehen, sonst wuerde ein nur bearbeitetes Bild als vollstaendig generiert gekennzeichnet.
$GLOBALS['TL_DCA']['tl_page']['fields']['netzhirschAiTagTextModified'] = [
    'exclude' => true,
    'inputType' => 'text',
    'eval' => ['maxlength' => 128, 'tl_class' => 'long', 'decodeEntities' => true],
    'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
];

$netzhirschAiTagPalette = PaletteManipulator::create()
    ->addLegend('netzhirsch_ai_tag_legend', 'global_legend', PaletteManipulator::POSITION_BEFORE, true)
    ->addField(['netzhirschAiTagText', 'netzhirschAiTagTextModified'], 'netzhirsch_ai_tag_legend', PaletteManipulator::POSITION_APPEND)
;

foreach (['root', 'rootfallback'] as $netzhirschAiTagRootPalette) {
    $netzhirschAiTagPalette->applyToPalette($netzhirschAiTagRootPalette, 'tl_page');
}

unset($netzhirschAiTagPalette, $netzhirschAiTagRootPalette);
