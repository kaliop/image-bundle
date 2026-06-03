<?php declare(strict_types=1);

namespace Kaliop\Bundle\Image\DependencyInjection\Compiler;

use Ibexa\Bundle\Core\Imagine\Variation\ImagineAwareAliasGenerator;
use Kaliop\Image\VariationHandler\FastlyVariationHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class FastlyServiceDecorationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('Ibexa\Fastly\ImageOptimizer\VariationHandler')) {
            $container
                ->register(FastlyVariationHandler::class, FastlyVariationHandler::class)
                ->setAutowired(true)
                ->setDecoratedService('Ibexa\Fastly\ImageOptimizer\VariationHandler')
                ->setArgument(0, new Reference('Ibexa\Fastly\ImageOptimizer\VariationResolver'))
                ->setArgument(4, new Reference(ImagineAwareAliasGenerator::class))
                ->addTag('ibexa.media.images.variation.handler', [
                    'identifier' => 'fastly',
                ]);
        }
    }
}
