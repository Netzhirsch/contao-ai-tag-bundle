<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle;

use Contao\CoreBundle\Filesystem\Dbafs\DbafsChangeEvent;
use Netzhirsch\ContaoAiTagBundle\Detection\AiSourceInspector;
use Netzhirsch\ContaoAiTagBundle\Image\TagStyle;
use Netzhirsch\ContaoAiTagBundle\Twig\AiTagExtension;
use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class NetzhirschContaoAiTagBundle extends AbstractBundle
{
    /**
     * Farbangaben als Hex, drei- oder sechsstellig.
     */
    private const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode();

        // children() gibt es nur auf der ArrayNodeDefinition, waehrend Symfony den
        // Rueckgabetyp von rootNode() als NodeDefinition deklariert (in 6.4 als Union
        // aus beidem). Zur Laufzeit ist es immer eine ArrayNodeDefinition - deshalb hier
        // verengen und andernfalls laut scheitern, statt es anzunehmen.
        if (!$rootNode instanceof ArrayNodeDefinition) {
            throw new \LogicException(\sprintf('Die Bundle-Konfiguration erwartet eine %s, erhalten wurde %s.', ArrayNodeDefinition::class, get_debug_type($rootNode)));
        }

        $rootNode
            ->children()
            // --- Schrift und Groesse ---
            ->scalarNode('font_path')
            ->info('Absoluter Pfad zu einer TrueType-Schrift fuer die Kennzeichnung. Ohne Angabe werden gaengige System-Schriften gesucht.')
            ->defaultNull()
            ->end()
            ->integerNode('min_font_size')
            ->info('Kleinste Schriftgroesse in Pixel. Passt das Label damit nicht mehr ins Bild, wird es weggelassen.')
            ->defaultValue(11)
            ->min(6)
            ->end()
            ->floatNode('relative_font_size')
            ->info('Wunsch-Schriftgroesse relativ zur Bildbreite.')
            ->defaultValue(0.03)
            ->min(0.005)
            ->max(0.5)
            ->end()
            ->integerNode('max_font_size')
            ->info('Groesste Schriftgroesse in Pixel. Ohne Deckel wuerde das Label auf grossen Bildern zum Bildelement.')
            ->defaultValue(48)
            ->min(8)
            ->end()
            // --- Gestaltung ---
            ->enumNode('style')
            ->info('box = Text auf halbtransparenter Flaeche, outline = Text mit Kontur, plain = nur Text.')
            ->values(TagStyle::STYLES)
            ->defaultValue(TagStyle::STYLE_BOX)
            ->end()
            ->scalarNode('text_color')
            ->info('Hex-Farbe des Textes. Ohne Angabe automatisch hell oder dunkel, je nach Untergrund.')
            ->defaultNull()
            ->validate()
            ->ifTrue(static fn (mixed $value): bool => null !== $value && 1 !== preg_match(self::COLOR_PATTERN, (string) $value))
            ->thenInvalid('text_color muss eine Hex-Farbe wie #ffffff sein, "%s" ist keine.')
            ->end()
            ->end()
            ->scalarNode('box_color')
            ->info('Hex-Farbe der Flaeche bzw. der Kontur. Ohne Angabe automatisch als Gegenfarbe zum Text.')
            ->defaultNull()
            ->validate()
            ->ifTrue(static fn (mixed $value): bool => null !== $value && 1 !== preg_match(self::COLOR_PATTERN, (string) $value))
            ->thenInvalid('box_color muss eine Hex-Farbe wie #000000 sein, "%s" ist keine.')
            ->end()
            ->end()
            ->integerNode('box_opacity')
            ->info('Deckkraft der Label-Flaeche in Prozent.')
            ->defaultValue(60)
            ->min(0)
            ->max(100)
            ->end()
            ->floatNode('corner_radius')
            ->info('Eckenradius der Flaeche, relativ zu ihrer Hoehe. 0 ergibt rechte Winkel, 0.5 eine Pillenform.')
            ->defaultValue(0.25)
            ->min(0.0)
            ->max(0.5)
            ->end()
            ->floatNode('padding_ratio')
            ->info('Innenabstand der Flaeche, relativ zur Schriftgroesse.')
            ->defaultValue(0.45)
            ->min(0.0)
            ->max(2.0)
            ->end()
            ->floatNode('margin_ratio')
            ->info('Abstand zum Bildrand, relativ zur Schriftgroesse.')
            ->defaultValue(0.5)
            ->min(0.0)
            ->max(5.0)
            ->end()
            ->booleanNode('uppercase')
            ->info('Kennzeichnung in Grossbuchstaben ausgeben.')
            ->defaultFalse()
            ->end()
            // --- Reichweite ---
            ->floatNode('max_box_width')
            ->info('Maximaler Anteil der Bildbreite, den das Label belegen darf.')
            ->defaultValue(0.65)
            ->min(0.1)
            ->max(1.0)
            ->end()
            ->floatNode('max_box_height')
            ->info('Maximaler Anteil der Bildhoehe, den das Label belegen darf.')
            ->defaultValue(0.3)
            ->min(0.05)
            ->max(1.0)
            ->end()
            ->integerNode('min_width')
            ->info('Bildgroessen unterhalb dieser Breite werden nicht gekennzeichnet. 0 schaltet die Pruefung ab.')
            ->defaultValue(0)
            ->min(0)
            ->end()
            ->integerNode('min_height')
            ->info('Bildgroessen unterhalb dieser Hoehe werden nicht gekennzeichnet. 0 schaltet die Pruefung ab.')
            ->defaultValue(0)
            ->min(0)
            ->end()
            ->arrayNode('excluded_paths')
            ->info('Pfade, die nie gekennzeichnet werden - auch nicht ueber die Ordner-Vererbung. Beispiel: files/icons')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            // --- Markup ---
            ->enumNode('hint_placement')
            ->info('Wohin die barrierefreie Textfassung geht: alt, caption, both oder none.')
            ->values(AiTagExtension::PLACEMENTS)
            ->defaultValue(AiTagExtension::PLACEMENT_ALT)
            ->end()
            ->scalarNode('hint_separator')
            ->info('Trenner zwischen vorhandenem Text und der Kennzeichnung.')
            ->defaultValue(' – ')
            ->end()
            ->enumNode('detection')
            ->info('Erkennung beim Hinzufuegen von Dateien: suggest merkt nur an, was sich selbst als KI-generiert ausweist, auto setzt die Kennzeichnung, off schaltet ab.')
            ->values(AiSourceInspector::MODES)
            ->defaultValue(AiSourceInspector::MODE_SUGGEST)
            ->end()
            ->booleanNode('tag_backend_images')
            ->info('Auch Bilder im Backend kennzeichnen. Standardmaessig aus: in der Dateiverwaltung soll die Datei zu sehen sein, nicht die Auslieferung - die Gegenueberstellung leistet das Vorschaufeld in der Dateibearbeitung.')
            ->defaultFalse()
            ->end()
            // --- Betrieb ---
            ->integerNode('intermediate_quality')
            ->info('Qualitaet der ersten Kodierung. Die Nachbearbeitung kodiert ein zweites Mal, deshalb bewusst hoch.')
            ->defaultValue(95)
            ->min(1)
            ->max(100)
            ->end()
            ->integerNode('log_retention_days')
            ->info('Aufbewahrungsfrist des Kennzeichnungs-Protokolls in Tagen. 0 bewahrt unbegrenzt auf.')
            ->defaultValue(1095)
            ->min(0)
            ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach ($config as $key => $value) {
            $builder->setParameter('netzhirsch_contao_ai_tag.'.$key, $value);
        }

        $container->import('../config/services.yaml');

        // DbafsChangeEvent deckt jeden Weg in tl_files ab, gibt es aber erst ab Contao
        // 5.5. Auf 5.3 bleibt nur der Upload-Hook des Dateimanagers.
        $container->import(class_exists(DbafsChangeEvent::class)
            ? '../config/services_detection_event.yaml'
            : '../config/services_detection_hook.yaml');

        // Die MCP-Werkzeuge sind optional: ohne netzhirsch/contao-mcp-bundle gibt es die
        // Basisklasse nicht, und der Service duerfte nicht registriert werden.
        if (class_exists(AbstractMcpTool::class)) {
            $container->import('../config/services_mcp.yaml');
        }
    }
}
