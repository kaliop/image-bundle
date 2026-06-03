<?php declare(strict_types=1);

namespace Kaliop\Image\Imagine\Filter;

use Imagine\Filter\Basic\Crop;
use Imagine\Filter\Basic\Thumbnail;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use InvalidArgumentException;
use Liip\ImagineBundle\Imagine\Filter\Loader\LoaderInterface;

class FocalPointThumbnailFilterLoader implements LoaderInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function load(ImageInterface $image, array $options = []): ImageInterface
    {
        $mode = ImageInterface::THUMBNAIL_OUTBOUND;
        if (!empty($options['mode']) && 'inset' === $options['mode']) {
            $mode = ImageInterface::THUMBNAIL_INSET;
        }

        $filter = $options['filter'] ?? 'undefined';
        $filter = \constant('Imagine\Image\ImageInterface::FILTER_' . mb_strtoupper($filter)) ?? ImageInterface::FILTER_UNDEFINED;

        if (!is_array($options['size'] ?? null)) {
            $options['size'] = [];
        }

        $width = $options['size'][0] ?? null;
        $height = $options['size'][1] ?? null;

        if (null === $width || null === $height) {
            throw new InvalidArgumentException('Size (width and height) must be specified.');
        }

        $width = (int) $width;
        $height = (int) $height;

        $focalPoint = $options['focal_point'] ?? [0, 0];
        if (!is_array($focalPoint) || count($focalPoint) !== 2) {
            throw new InvalidArgumentException('Focal point must be an array with two elements: [x, y].');
        }

        [$fpX, $fpY] = $focalPoint;

        $fpX = (int) $fpX;
        $fpY = (int) $fpY;

        $size = $image->getSize();
        $origWidth = $size->getWidth();
        $origHeight = $size->getHeight();

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
        $cropX = max(0, min($fpX - (int)($cropWidth / 2), $origWidth - $cropWidth));
        $cropY = max(0, min($fpY - (int)($cropHeight / 2), $origHeight - $cropHeight));

        // Apply the crop
        $cropFilter = new Crop(new Point($cropX, $cropY), new Box($cropWidth, $cropHeight));
        $image = $cropFilter->apply($image);

        // Create the thumbnail from the cropped image
        $thumbFilter = new Thumbnail(new Box($width, $height), $mode, $filter);
        $image = $thumbFilter->apply($image);

        return $image;
    }
}
