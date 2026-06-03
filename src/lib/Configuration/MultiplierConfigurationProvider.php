<?php

declare(strict_types=1);

namespace Kaliop\Image\Configuration;

use Symfony\Component\OptionsResolver\OptionsResolver;

class MultiplierConfigurationProvider
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

    /**
     * @return float[]
     */
    public function getMultipliers(): array
    {
        return array_map('floatval', $this->config['multipliers']);
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
        return $this->config['ignore_variations_multipliers'];
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multipliers' => [],
            'ignore_variations_multipliers' => [],
        ]);

        $resolver->setAllowedTypes('multipliers', ['array']);
        $resolver->setAllowedTypes('ignore_variations_multipliers', ['string[]']);
    }
}
