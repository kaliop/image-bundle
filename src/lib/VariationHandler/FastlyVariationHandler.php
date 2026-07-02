<?php

declare(strict_types=1);

namespace Kaliop\Image\VariationHandler;

use Ibexa\Bundle\Core\Variation\PathResolver;
use Ibexa\Contracts\Core\FieldType\Value;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Ibexa\Contracts\Core\Variation\Values\ImageVariation;
use Ibexa\Contracts\Core\Variation\Values\Variation;
use Ibexa\Contracts\Core\Variation\VariationHandler;
use Ibexa\Core\Base\Exceptions\InvalidArgumentException;
use Ibexa\Core\FieldType\Image\Value as ImageValue;
use Ibexa\Core\FieldType\ImageAsset\AssetMapper;
use Ibexa\Core\FieldType\ImageAsset\Value as ImageAssetValue;

/**
 * Overriding Ibexa\Fastly\ImageOptimizer\VariationHandler to pass focal point metadata to a configuration.
 */
class FastlyVariationHandler implements VariationHandler
{
    public const IDENTIFIER = 'fastly';

    public function __construct(
        private PathResolver $variationResolver,
        private ContentService $contentService,
        private AssetMapper $assetMapper,
        private ConfigResolverInterface $configResolver,
        private VariationHandler $referenceHandler
    ) {}

    /**
     * @param Field $field
     * @param VersionInfo $versionInfo
     * @param string $variationName
     * @param array<string, mixed> $parameters
     *
     * @return Variation
     *
     * @throws InvalidArgumentException
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\InvalidArgumentException
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    public function getVariation(
        Field $field,
        VersionInfo $versionInfo,
        $variationName,
        array $parameters = []
    ): Variation {
        $value = $field->getValue();

        if (!$this->supports($value)) {
            throw new InvalidArgumentException(
                '$field',
                sprintf(
                    'Value of Field with ID %d (%s) cannot be used for generating an image variation.',
                    $field->id,
                    $field->fieldDefIdentifier
                )
            );
        }

        $configuration = $this->configResolver->getParameter('fastly_variations');
        $additionalData = [];

        if ($value instanceof ImageAssetValue) {
            $destinationImage = $this->contentService->loadContent(
                (int)$value->destinationContentId
            );
            $value = $this->assetMapper->getAssetValue($destinationImage);
            $versionInfo = $destinationImage->versionInfo;

            $additionalData = $value->additionalData ?: [];
        } elseif ($value instanceof ImageValue) {
            $additionalData = $value->additionalData ?: [];
        }

        if (!empty($configuration[$variationName]['mime_types'])) {
            $mimeType = mime_content_type($value->uri);

            if (!in_array($mimeType, $configuration[$variationName]['mime_types'], true)) {
                return $this->referenceHandler->getVariation(
                    $field,
                    $versionInfo,
                    $variationName,
                    $parameters
                );
            }
        }

        if (isset($configuration[$variationName]['reference'])) {
            $reference = $this->referenceHandler->getVariation(
                $field,
                $versionInfo,
                $configuration[$variationName]['reference'],
                $parameters
            );

            $path = $reference->uri;
            $width = isset($reference->width) ? $reference->width : 0;
            $height = isset($reference->height) ? $reference->height : 0;
        } else {
            $path = $value->uri;
            $width = $value->width;
            $height = $value->height;
        }

        $uri = $this->updateFocalPointCrop(
            $this->variationResolver->resolve(
                $path,
                $variationName
            ),
            (int) $width,
            (int) $height,
            isset($additionalData['focalPointX']) ? (int) ($additionalData['focalPointX']) : null,
            isset($additionalData['focalPointY']) ? (int) ($additionalData['focalPointY']) : null
        );

        [$variationWidth, $variationHeight] = $this->resolveVariationDimensions(
            $configuration[$variationName]['configuration'] ?? [],
            (int) $value->width,
            (int) $value->height
        );

        return new ImageVariation([
            'imageId' => $value->imageId,
            'name' => $variationName,
            'handler' => self::IDENTIFIER,
            'isExternal' => true,
            'lastModified' => $versionInfo->modificationDate,
            'width' => $variationWidth,
            'height' => $variationHeight,
            'uri' => $uri,
        ]);
    }

    private function supports(Value $value): bool
    {
        return $value instanceof ImageValue || $value instanceof ImageAssetValue;
    }

    /**
     * Resolve the pixel dimensions Fastly will serve for a variation.
     *
     * The Fastly handler performs no local image processing, so unlike the Imagine
     * handler it would otherwise return an ImageVariation with null width/height.
     * Templates relying on ibexa_image_alias(...).width/height (e.g. <canvas> aspect
     * boxes) then render empty dimensions. We reconstruct the dimensions from the
     * Fastly variation configuration and the source image size.
     *
     * @param array<string, mixed> $configuration
     *
     * @return array{0: int|null, 1: int|null} [width, height]
     */
    private function resolveVariationDimensions(array $configuration, int $sourceWidth, int $sourceHeight): array
    {
        // Fixed crop, e.g. "1344,756,focal-point" -> exact output size.
        if (isset($configuration['crop']) && is_string($configuration['crop'])) {
            $parts = explode(',', $configuration['crop']);
            if (isset($parts[0], $parts[1]) && is_numeric($parts[0]) && is_numeric($parts[1])) {
                return [(int) $parts[0], (int) $parts[1]];
            }
        }

        // Bounded scale, e.g. { width: 1344, height: 100p, fit: bounds } -> scale to
        // fit the width preserving aspect ratio, never upscaling ("100p" caps height
        // at the source height, so the width is the binding constraint).
        if (
            isset($configuration['fit']) && $configuration['fit'] === 'bounds'
            && $sourceWidth > 0 && $sourceHeight > 0
        ) {
            $maxWidth = isset($configuration['width']) && is_numeric($configuration['width'])
                ? (int) $configuration['width']
                : $sourceWidth;
            $scale = min($maxWidth / $sourceWidth, 1.0);

            return [(int) round($sourceWidth * $scale), (int) round($sourceHeight * $scale)];
        }

        // Explicit numeric width/height, otherwise fall back to the source dimensions.
        $width = isset($configuration['width']) && is_numeric($configuration['width'])
            ? (int) $configuration['width']
            : ($sourceWidth ?: null);
        $height = isset($configuration['height']) && is_numeric($configuration['height'])
            ? (int) $configuration['height']
            : ($sourceHeight ?: null);

        return [$width, $height];
    }

    /**
     * When uri contains a crop parameter with a focal-point value, update the parameter with a correct XY values.
     */
    private function updateFocalPointCrop(
        string $uri,
        int $width,
        int $height,
        ?int $focalPointX = null,
        ?int $focalPointY = null
    ): string {
        $parts = parse_url($uri);

        $params = [];
        parse_str($parts['query'] ?? '', $params);

        $crop = isset($params['crop']) && !is_array($params['crop']) ? $params['crop'] : null;
        if (!$crop || !str_contains($crop, 'focal-point')) {
            return $uri;
        }

        $focalPointX ??= (int)round($width / 2);
        $focalPointY ??= (int)round($height / 2);

        $origWidth = $width;
        $origHeight = $height;

        list($width, $height) = array_map('intval', explode(',', $crop));

        // Determine whether to use full width or height
        if ($origWidth / $origHeight > $width / $height) {
            // Wider than the target ratio: crop using full height
            $cropHeight = $origHeight;
            $cropWidth = (int)($width * ($origHeight / $height));
        } else {
            // Taller than the target ratio: crop using full width
            $cropWidth = $origWidth;
            $cropHeight = (int)($height * ($origWidth / $width));
        }

        // Calculate the top-left corner for the crop centered around the focal point
        $cropX = max(0, min($focalPointX - (int)($cropWidth / 2), $origWidth - $cropWidth));
        $cropY = max(0, min($focalPointY - (int)($cropHeight / 2), $origHeight - $cropHeight));

        $params['width'] = $width;
        $params['height'] = $height;
        $params['crop'] = sprintf('%d,%d,x%d,y%d,safe', $cropWidth, $cropHeight, $cropX, $cropY);

        return ($parts['scheme'] ?? 'https') . '://' .
            ($parts['host'] ?? '') .
            ($parts['path'] ?? '') .
            '?' . http_build_query($params);
    }
}
