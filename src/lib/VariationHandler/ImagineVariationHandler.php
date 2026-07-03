<?php declare(strict_types=1);

namespace Kaliop\Image\VariationHandler;

use Ibexa\Bundle\Core\Imagine\IORepositoryResolver;
use Ibexa\Contracts\Core\FieldType\Value;
use Ibexa\Contracts\Core\Repository\Exceptions\InvalidVariationException;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Contracts\Core\Variation\Values\ImageVariation;
use Ibexa\Contracts\Core\Variation\VariationHandler;
use Ibexa\Core\FieldType\Image\Value as ImageValue;
use Ibexa\Core\MVC\Exception\SourceImageNotFoundException;
use Imagine\Exception\RuntimeException;
use InvalidArgumentException;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Binary\Loader\LoaderInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Exception\Imagine\Cache\Resolver\NotResolvableException;
use Liip\ImagineBundle\Imagine\Cache\Resolver\ResolverInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterConfiguration;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;

/**
 * Overriding Ibexa\Bundle\Core\Imagine\AliasGenerator to pass focal point metadata to a filter.
 */
class ImagineVariationHandler implements VariationHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private LoaderInterface $dataLoader,
        private FilterManager $filterManager,
        private ResolverInterface $ioResolver,
        private FilterConfiguration $filterConfiguration,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = null !== $logger ? $logger : new NullLogger();
    }

    /**
     * @param string $variationName
     * @param array<string, mixed> $parameters
     */
    public function getVariation(
        Field $field,
        VersionInfo $versionInfo,
        $variationName,
        array $parameters = []
    ): ImageVariation {
        /** @var ImageValue $imageValue */
        $imageValue = $field->value;

        /** @var string $fieldId */
        $fieldId = $field->id;
        $fieldDefIdentifier = $field->fieldDefIdentifier;
        if (!$this->supportsValue($imageValue)) {
            throw new InvalidArgumentException("Value of Field with ID $fieldId ($fieldDefIdentifier) cannot be used for generating an image variation.");
        }

        $originalPath = (string) ($imageValue->id);

        $variationWidth = $variationHeight = null;
        // Create the image alias only if it does not already exist.
        if ($variationName !== IORepositoryResolver::VARIATION_ORIGINAL && !$this->ioResolver->isStored($originalPath, $variationName)) {
            try {
                $originalBinary = $this->dataLoader->find($originalPath);
            } catch (NotLoadableException $e) {
                throw new SourceImageNotFoundException((string) $originalPath, 0, $e);
            }

            if (!$originalBinary instanceof BinaryInterface) {
                throw new InvalidArgumentException("Original image for Field with ID $fieldId ($fieldDefIdentifier) is not a valid binary.");
            }

            $this->logger->debug("Generating '$variationName' variation on $originalPath, field #$fieldId ($fieldDefIdentifier)");

            $this->ioResolver->store(
                $this->applyFilter($imageValue, $originalBinary, $variationName),
                $originalPath,
                $variationName
            );
        } else {
            if ($variationName === IORepositoryResolver::VARIATION_ORIGINAL) {
                $variationWidth = $imageValue->width;
                $variationHeight = $imageValue->height;
            }
            $this->logger->debug("'$variationName' variation on $originalPath is already generated. Loading from cache.");
        }

        try {
            $aliasInfo = new SplFileInfo(
                $this->ioResolver->resolve($originalPath, $variationName)
            );
        } catch (NotResolvableException $e) {
            // If for some reason image alias cannot be resolved, throw the appropriate exception.
            throw new InvalidVariationException($variationName, 'image', 0, $e);
        } catch (RuntimeException $e) {
            throw new InvalidVariationException($variationName, 'image', 0, $e);
        }

        return new ImageVariation(
            [
                'name' => $variationName,
                'fileName' => $aliasInfo->getFilename(),
                'dirPath' => $aliasInfo->getPath(),
                'uri' => $aliasInfo->getPathname(),
                'imageId' => $imageValue->imageId,
                'width' => $variationWidth,
                'height' => $variationHeight,
            ]
        );
    }

    private function applyFilter(
        ImageValue $value,
        BinaryInterface $image,
        string $variationName
    ): BinaryInterface {
        $filterConfig = $this->filterConfiguration->get($variationName);
        // If the variation has a reference, we recursively call this method to apply reference's filters.
        if (isset($filterConfig['reference']) && $filterConfig['reference'] !== IORepositoryResolver::VARIATION_ORIGINAL) {
            $image = $this->applyFilter($value, $image, $filterConfig['reference']);
        }

        $config = [];
        if ($filterConfig['filters']['thumbnail/focal-point'] ?? false) {
            $focalPointX = $value->additionalData['focalPointX'] ?? round($value->width / 2);
            $focalPointY = $value->additionalData['focalPointY'] ?? round($value->height / 2);

            $config['filters']['thumbnail/focal-point']['focal_point'] = [$focalPointX, $focalPointY];
        }

        return $this->filterManager->applyFilter($image, $variationName, $config);
    }

    public function supportsValue(Value $value): bool
    {
        return $value instanceof ImageValue;
    }
}
