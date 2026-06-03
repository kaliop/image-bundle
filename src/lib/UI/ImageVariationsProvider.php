<?php
declare(strict_types=1);

namespace Kaliop\Image\UI;

use Ibexa\Contracts\AdminUi\UI\Config\ProviderInterface;
use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ImageVariationsProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly ConfigResolverInterface $configResolver,
        private array $config = [],
    ) {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);

        $this->config = $resolver->resolve($config);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $imageVariations = $this->configResolver->getParameter('image_variations');

        if ($this->config['visible_image_variations']) {
            foreach ($imageVariations as $variation => $settings) {
                if (!in_array($variation, $this->config['visible_image_variations'])) {
                    unset($imageVariations[$variation]);
                }
            }
        }

        return $imageVariations;
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'visible_image_variations' => [],
        ]);

        $resolver->setAllowedTypes('visible_image_variations', ['string[]']);
    }
}
