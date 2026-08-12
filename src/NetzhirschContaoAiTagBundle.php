<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle;

use Netzhirsch\ContaoMcpBundle\Extension\AbstractMcpTool;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class NetzhirschContaoAiTagBundle extends AbstractBundle
{
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
            ->integerNode('box_opacity')
            ->info('Deckkraft der Label-Flaeche in Prozent.')
            ->defaultValue(60)
            ->min(0)
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

        // Die MCP-Werkzeuge sind optional: ohne netzhirsch/contao-mcp-bundle gibt es die
        // Basisklasse nicht, und der Service duerfte nicht registriert werden.
        if (class_exists(AbstractMcpTool::class)) {
            $container->import('../config/services_mcp.yaml');
        }
    }
}
