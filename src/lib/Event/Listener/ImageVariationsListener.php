<?php

declare(strict_types=1);

namespace Kaliop\Image\Event\Listener;

use Ibexa\Bundle\Core\Imagine\Filter\FilterConfiguration;
use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Kaliop\Image\Configuration\MultiplierConfigurationProvider;
use Kaliop\Image\Configuration\WebpConfigurationProvider;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ImageVariationsListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly FilterConfiguration $filterConfiguration,
        private readonly ConfigResolverInterface $configResolver,
        private readonly MultiplierConfigurationProvider $multiplierConfigurationProvider,
        private readonly WebpConfigurationProvider $webpConfigurationProvider,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            ConsoleEvents::COMMAND => 'onCommand',
        ];
    }

    public function onKernelRequest(): void
    {
        $this->updateImageVariations();
    }

    public function onCommand(): void
    {
        $this->updateImageVariations();
    }

    /**
     * Dynamically updates image variations by adding multiplier variations as well as webp format.
     */
    protected function updateImageVariations(): void
    {
        $dynamicVariations = [];
        $configuredVariations = $this->configResolver->getParameter('image_variations');

        foreach ($configuredVariations as $variation => $config) {
            if (!$config) {
                continue;
            }

            // Webp format
            if ($this->webpConfigurationProvider->isWebpEnabled() && $this->webpConfigurationProvider->isVariationSupported($variation)) {
                $webpConfig = $config;
                $webpConfig['format'] = 'webp';
                $dynamicVariations[$variation . '-webp'] = $webpConfig;
            }

            if ($this->multiplierConfigurationProvider->isVariationSupported($variation)) {
                foreach ($this->multiplierConfigurationProvider->getMultipliers() as $multiplier) {
                    // Multiplier
                    $dynamicVariations[$variation . '_x' . $multiplier] = $this->getImageVariationMultiplierConfig($multiplier, $config);

                    // Webp format
                    if ($this->webpConfigurationProvider->isWebpEnabled() && $this->webpConfigurationProvider->isVariationSupported($variation)) {
                        $webpConfig = $dynamicVariations[$variation . '_x' . $multiplier];
                        $webpConfig['format'] = 'webp';

                        $dynamicVariations[$variation . '_x' . $multiplier . '-webp'] = $webpConfig;
                    }
                }
            }
        }

        // Update configuration
        foreach ($dynamicVariations as $variationName => $config) {
            $this->filterConfiguration->set($variationName, $config);
        }
    }

    /**
     * Scale given image variation configuration by a given multiplier.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function getImageVariationMultiplierConfig(
        float $multiplier,
        array $config
    ): array {
        if (isset($config['filters'])) {
            foreach ($config['filters'] as $filter => $filterConfig) {
                if (is_array($filterConfig)) {
                    if (array_filter($filterConfig, 'is_int')) {
                        // Ibexa filters
                        $config['filters'][$filter] = array_map(static function ($value) use ($multiplier) {
                            return (int)round($value * $multiplier);
                        }, $filterConfig);
                    } else {
                        // LiipImagineBundle filters
                        foreach ($filterConfig as $parameter => $value) {
                            if (is_int($value)) {
                                $config['filters'][$filter][$parameter] = (int)round($value * $multiplier);
                            } elseif (is_array($value) && array_filter($value, 'is_int')) {
                                $config['filters'][$filter][$parameter] = array_map(static function ($subValue) use ($multiplier) {
                                    return (int)round($subValue * $multiplier);
                                }, $value);
                            }
                        }
                    }
                }
            }
        }

        return $config;
    }
}
