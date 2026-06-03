<?php

declare(strict_types=1);

namespace Kaliop\Bundle\Image\Templating;

use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Contracts\Core\Variation\VariationHandler;
use Kaliop\Image\Configuration\MultiplierConfigurationProvider;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @internal
 */
class ImageSourceExtension extends AbstractExtension
{
    public function __construct(
        private readonly VariationHandler $imageVariationService,
        private readonly MultiplierConfigurationProvider $configurationProvider,
        private readonly LoggerInterface $logger,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'kaliop_image_srcset',
                [$this, 'getImageSrcset'],
            ),
        ];
    }

    public function getImageSrcset(
        Field $field,
        VersionInfo $versionInfo,
        string $variation,
        bool $webp = false
    ): string {
        $srcset = [
            $this->getImageVariationUrl($field, $versionInfo, $webp ? $variation . '-webp' : $variation),
        ];

        if ($this->configurationProvider->isVariationSupported($variation)) {
            foreach ($this->configurationProvider->getMultipliers() as $multiplier) {
                $src = $this->getImageVariationUrl($field, $versionInfo, $variation . '_x' . $multiplier . ($webp ? '-webp' : ''));
                if ($src) {
                    $srcset[] = $src . ' ' . $multiplier . 'x';
                }
            }
        }

        return implode(",\n", array_filter($srcset));
    }

    private function getImageVariationUrl(
        Field $field,
        VersionInfo $versionInfo,
        string $variationName
    ): ?string {
        try {
            return str_replace(
                ' ',
                '%20',
                $this->imageVariationService->getVariation($field, $versionInfo, $variationName)->uri,
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Error getting image variation "%s": %s', $variationName, $e->getMessage()), ['exception' => $e]);

            return null;
        }
    }
}
