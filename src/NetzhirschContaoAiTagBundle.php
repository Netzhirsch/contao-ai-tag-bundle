<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoAiTagBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class NetzhirschContaoAiTagBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
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
    }
}
