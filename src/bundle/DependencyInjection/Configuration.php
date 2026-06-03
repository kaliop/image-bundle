<?php

declare(strict_types=1);

namespace Kaliop\Bundle\Image\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('kaliop_image');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('image_variations')
                    ->children()
                        ->booleanNode('enable_webp')
                            ->info('Generates custom image variations suffixed with "-webp" for webp format images')
                            ->defaultValue(true)
                        ->end()
                        ->arrayNode('multipliers')
                            ->info('Generates custom image variations for a given multipliers suffixed with "_x(multiplier)", for example: "_x1.25"')
                            ->prototype('scalar')
                            ->end()
                        ->end()
                        ->arrayNode('ignore_variations_webp')
                            ->info('List of image variations for which custom webp image variation should be skipped')
                            ->prototype('scalar')
                            ->end()
                            ->defaultValue(['original'])
                        ->end()
                        ->arrayNode('ignore_variations_multipliers')
                            ->info('List of image variations for which custom multipliers image variations should be skipped')
                            ->prototype('scalar')
                            ->end()
                            ->defaultValue(['original'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('admin_ui')
                    ->children()
                        ->arrayNode('visible_image_variations')
                            ->info('Filter visible image variations in AdminUI')
                            ->prototype('scalar')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
