<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Netzhirsch\ContaoAiTagBundle\Image\AiTagOptions;

/*
 * Kennzeichnung in der Dateiverwaltung.
 *
 * Die Felder gelten fuer Dateien UND Ordner: ein markierter Ordner kennzeichnet
 * alle enthaltenen Bilder (der naechstliegende markierte Eintrag gewinnt).
 *
 * 'exclude' => true macht die Felder ueber die Contao-Feldrechte steuerbar; die
 * eigentliche Absicherung liegt zusaetzlich im FilesAiTagVoter, damit auch
 * Schreibzugriffe ausserhalb des Backend-Formulars geprueft werden.
 */

$GLOBALS['TL_DCA']['tl_files']['fields']['netzhirschAiGenerated'] = [
    'exclude' => true,
    'inputType' => 'checkbox',
    'eval' => ['submitOnChange' => true, 'tl_class' => 'clr'],
    'sql' => ['type' => 'string', 'length' => 1, 'default' => ''],
];

$GLOBALS['TL_DCA']['tl_files']['fields']['netzhirschAiTagPosition'] = [
    'exclude' => true,
    'inputType' => 'select',
    'reference' => &$GLOBALS['TL_LANG']['tl_files']['netzhirschAiTagPositionRef'],
    'eval' => ['includeBlankOption' => false, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 32, 'default' => AiTagOptions::POSITION_AUTO],
];

$GLOBALS['TL_DCA']['tl_files']['fields']['netzhirschAiTagText'] = [
    'exclude' => true,
    'inputType' => 'text',
    'eval' => ['maxlength' => 128, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 128, 'default' => ''],
];

/*
 * Virtuelles Feld ohne Datenbankspalte: stellt beide Fassungen gegenueber, sobald
 * die Kennzeichnung gesetzt ist. Gerendert vom AiTagPreviewListener.
 */
$GLOBALS['TL_DCA']['tl_files']['fields']['netzhirschAiTagPreview'] = [
    'exclude' => false,
];

$GLOBALS['TL_DCA']['tl_files']['palettes']['__selector__'][] = 'netzhirschAiGenerated';
$GLOBALS['TL_DCA']['tl_files']['subpalettes']['netzhirschAiGenerated'] = 'netzhirschAiTagPosition,netzhirschAiTagText,netzhirschAiTagPreview';

PaletteManipulator::create()
    ->addField('netzhirschAiGenerated', 'syncExclude', PaletteManipulator::POSITION_AFTER)
    ->applyToPalette('default', 'tl_files')
;
