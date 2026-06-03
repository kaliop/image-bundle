<?php

declare(strict_types=1);

namespace Kaliop\Bundle\Image\DependencyInjection;

use Kaliop\Image\Configuration\MultiplierConfigurationProvider;
use Kaliop\Image\Configuration\WebpConfigurationProvider;
use Kaliop\Image\UI\ImageVariationsProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class KaliopImageExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition(MultiplierConfigurationProvider::class);
        $definition->setArgument(0, $config['image_variations'] ?? []);

        $definition = $container->getDefinition(WebpConfigurationProvider::class);
        $definition->setArgument(0, $config['image_variations'] ?? []);

        $definition = $container->getDefinition(ImageVariationsProvider::class);
        $definition->setArgument(1, $config['admin_ui'] ?? []);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFastlyVariationsConfiguration($container);
    }

    private function prependFastlyVariationsConfiguration(ContainerBuilder $container): void
    {
        $config = array_replace_recursive(...$container->getExtensionConfig('kaliop_image'));

        $multiplierProvider = new MultiplierConfigurationProvider($config['image_variations'] ?? []);
        $webpProvider = new WebpConfigurationProvider($config['image_variations'] ?? []);

        $fastlyVariationsConfig = [];

        $ibexaConfig = $container->getExtensionConfig('ibexa');
        foreach ($ibexaConfig as $config) {
            if (isset($config['system']) && is_array($config['system'])) {
                foreach ($config['system'] as $namespace => $value) {
                    if (isset($value['fastly_variations']) && is_array($value['fastly_variations'])) {
                        foreach ($value['fastly_variations'] as $variation => $config) {
                            // Multipliers
                            $multipliersConfiguration = [];
                            if ($multiplierProvider->isVariationSupported($variation)) {
                                foreach ($multiplierProvider->getMultipliers() as $multiplier) {
                                    $multipliersConfiguration[$variation . '_x' . $multiplier] = array_replace_recursive($config, [
                                        'configuration' => [
                                            'dpr' => $multiplier,
                                        ],
                                    ]);
                                }

                                $fastlyVariationsConfig[$namespace]['fastly_variations'] = array_merge(
                                    $fastlyVariationsConfig[$namespace]['fastly_variations'] ?? [],
                                    $multipliersConfiguration
                                );
                            }

                            // Webp
                            if ($webpProvider->isVariationSupported($variation)) {
                                $fastlyVariationsConfig[$namespace]['fastly_variations'][$variation . '-webp'] = array_replace_recursive($config, [
                                    'configuration' => [
                                        'format' => 'webp',
                                    ],
                                ]);

                                foreach ($multipliersConfiguration as $v => $c) {
                                    $fastlyVariationsConfig[$namespace]['fastly_variations'][$v . '-webp'] = array_replace_recursive($c, [
                                        'configuration' => [
                                            'format' => 'webp',
                                        ],
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($fastlyVariationsConfig) {
            $container->prependExtensionConfig('ibexa', [
                'system' => $fastlyVariationsConfig,
            ]);
        }
    }
}
