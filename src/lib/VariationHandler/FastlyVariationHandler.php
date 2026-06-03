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

        return new ImageVariation([
            'imageId' => $value->imageId,
            'name' => $variationName,
            'handler' => self::IDENTIFIER,
            'isExternal' => true,
            'lastModified' => $versionInfo->modificationDate,
            'uri' => $uri,
        ]);
    }

    private function supports(Value $value): bool
    {
        return $value instanceof ImageValue || $value instanceof ImageAssetValue;
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
