<?php

declare(strict_types=1);

namespace Kaliop\Image\Configuration;

use Symfony\Component\OptionsResolver\OptionsResolver;

class WebpConfigurationProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {
        $resolver = new OptionsResolver();
        $resolver->setDefined(array_keys($this->config));
        $this->configureOptions($resolver);

        $this->config = $resolver->resolve($config);
    }

    public function isWebpEnabled(): bool
    {
        return $this->config['enable_webp'];
    }

    public function isVariationSupported(string $variationName): bool
    {
        return !in_array($variationName, $this->getIgnoredVariations());
    }

    /**
     * @return string[]
     */
    public function getIgnoredVariations(): array
    {
        return $this->config['ignore_variations_webp'];
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'enable_webp' => true,
            'ignore_variations_webp' => [],
        ]);

        $resolver->setAllowedTypes('enable_webp', 'bool');
        $resolver->setAllowedTypes('ignore_variations_webp', ['string[]']);
    }
}
