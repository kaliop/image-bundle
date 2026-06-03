# Kaliop Image Bundle

A set of tools for managing image variations in Ibexa DXP.

The bundle extends Ibexa image handling with automatically generated WebP variations, density multiplier variations,
focal-point-aware cropping, Fastly Image Optimizer support, Admin UI image variation filtering, and a Twig helper for
building `srcset` attributes.

## Compatibility

| Bundle line | Ibexa | Symfony | PHP    |
|-------------|-------|---------|--------|
| 1.x         | 4.6   | 5.4 LTS | >= 8.1 |
| 2.x         | 5.0   | 7.4 LTS | >= 8.3 |

## Installation

Install the bundle with Composer:

```bash
composer require kaliop/image-bundle
```

If the bundle is not registered automatically by your application, add it to `config/bundles.php`:

```php
return [
    Kaliop\Bundle\Image\KaliopImageBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/kaliop_image.yaml`:

```yaml
kaliop_image:
    image_variations:
        # Generates custom image variations suffixed with "-webp" for WebP images.
        enable_webp: true

        # Generates custom image variations for the given multipliers.
        # Generated variation names are suffixed with "_x(multiplier)", for example: "_x1.5" or "_x2".
        multipliers: [ 1.5, 2 ]

        # Image variations for which WebP variations should not be generated.
        ignore_variations_webp: [ original ]

        # Image variations for which multiplier variations should not be generated.
        ignore_variations_multipliers: [ original ]

    admin_ui:
        # Limits image variations visible in the Ibexa Back Office RichText editor.
        visible_image_variations:
            - tiny
            - small
            - medium
            - large
```

## Automatically Generated Image Variations

The bundle reads the configured Ibexa image variations and adds extra variations at runtime.

Given this Ibexa image variation:

```yaml
ibexa:
    system:
        default:
            image_variations:
                large:
                    reference: original
                    filters:
                        thumbnail:
                            size: [ 800, 600 ]
                            mode: outbound
```

And this bundle configuration:

```yaml
kaliop_image:
    image_variations:
        enable_webp: true
        multipliers: [ 1.5, 2 ]
        ignore_variations_webp: [ original ]
        ignore_variations_multipliers: [ original ]
```

The bundle makes the following additional variations available:

- `large-webp` - same variation in WebP format
- `large_x1.5` - same variation with numeric filter dimensions scaled by `1.5`
- `large_x2` - same variation with numeric filter dimensions scaled by `2`
- `large_x1.5-webp` - scaled variation in WebP format
- `large_x2-webp` - scaled variation in WebP format

The multiplier generator scales integer filter values in both Ibexa-style and LiipImagineBundle-style filter
configuration. This allows standard local image variations to be used in the same way as Fastly Image Optimizer
variations.

Use `ignore_variations_webp` and `ignore_variations_multipliers` to exclude selected base variations from automatic
generation.

## Focal-Point Thumbnail Filter

The bundle provides a LiipImagineBundle filter loader named `thumbnail/focal-point`.

It works like the standard `thumbnail` filter with `outbound` cropping, but the crop area is calculated around the focal
point stored on the Ibexa image field. If the image has no focal point metadata, the image center is used.

Example local image variation:

```yaml
ibexa:
    system:
        default:
            image_variations:
                card:
                    reference: original
                    filters:
                        thumbnail/focal-point:
                            size: [ 640, 360 ]
                            mode: outbound
```

This generates a `640x360` crop that keeps the configured focal point inside the crop whenever possible.

The filter accepts the same relevant options as `thumbnail`:

- `size: [width, height]` - required target dimensions
- `mode: outbound` - crop to exact dimensions, default behavior
- `mode: inset` - resize inside the target box
- `filter` - optional Imagine resize filter name

You do not need to pass focal point coordinates manually in configuration. The bundle injects `focalPointX` and
`focalPointY` from the image field metadata when the variation is generated.

## Fastly Image Optimizer Support

When Ibexa Fastly Image Optimizer is installed, this bundle decorates its variation handler and applies the same
focal-point behavior to Fastly-generated URLs.

### Automatic Fastly WebP and Multiplier Variations

The bundle also extends configured `fastly_variations` with WebP and multiplier variants.

Given a Fastly variation named `card`, the same suffixes are generated:

- `card-webp`
- `card_x1.5`
- `card_x2`
- `card_x1.5-webp`
- `card_x2-webp`

For multiplier variants, the generated Fastly configuration receives the corresponding `dpr` value. For WebP variants,
the generated Fastly configuration receives `format: webp`.

### Focal-Point Crop Option

Fastly crop configuration can use `focal-point` as the third crop argument:

```yaml
ibexa:
    system:
        default:
            fastly_variations:
                card:
                    configuration:
                        crop: '640,360,focal-point'
```

The bundle converts this value into a Fastly crop rectangle based on the image focal point, then sets the requested
`width` and `height` query parameters.

If the image has no focal point metadata, the crop is centered.

## Admin UI Image Variation Filtering

Use `admin_ui.visible_image_variations` to limit which image variations are exposed in the Ibexa Back Office image
variation configuration, including the RichText editor image selection UI.

```yaml
kaliop_image:
    admin_ui:
        visible_image_variations:
            - tiny
            - small
            - medium
            - large
```

This does not remove or disable other image variations. It only hides them from the Admin UI configuration exposed to
editors.

## Twig `srcset` Helper

The bundle provides the Twig function `kaliop_image_srcset()` for generating a `srcset` value from the configured
multipliers.

Signature:

```twig
kaliop_image_srcset(field, versionInfo, variation, webp = false)
```

Example:

```twig
{% set image_field = content.getField('image') %}
{% set image = ibexa_image_alias(image_field, content.versionInfo, 'large') %}

<img
    src="{{ image.uri }}"
    srcset="{{ kaliop_image_srcset(image_field, content.versionInfo, 'large') }}"
    alt="{{ image_field.value.alternativeText|default('') }}"
>
```

Example with WebP sources:

```twig
{% set image_field = content.getField('image') %}
{% set fallback_image = ibexa_image_alias(image_field, content.versionInfo, 'large') %}

<picture>
    <source
        type="image/webp"
        srcset="{{ kaliop_image_srcset(image_field, content.versionInfo, 'large', true) }}"
    >
    <img
        src="{{ fallback_image.uri }}"
        srcset="{{ kaliop_image_srcset(image_field, content.versionInfo, 'large') }}"
        alt="{{ image_field.value.alternativeText|default('') }}"
    >
</picture>
```

For a `large` variation and `multipliers: [1.5, 2]`, the helper will try to use:

- `large`
- `large_x1.5`
- `large_x2`

When the fourth argument is `true`, it will use WebP variation names:

- `large-webp`
- `large_x1.5-webp`
- `large_x2-webp`

Missing variations are skipped and logged.

## Variation Naming Convention

The bundle uses the following naming convention for generated variations:

- WebP: `{variation}-webp`
- Multiplier: `{variation}_x{multiplier}`
- Multiplier WebP: `{variation}_x{multiplier}-webp`

Examples:

- `small-webp`
- `small_x1.5`
- `small_x2`
- `small_x2-webp`

## Contribute

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

This library is released under the MIT license. See the included
[LICENSE](LICENSE) file for more information.
