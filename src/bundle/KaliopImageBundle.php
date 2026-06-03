<?php

declare(strict_types=1);

namespace Kaliop\Bundle\Image;

use Kaliop\Bundle\Image\DependencyInjection\Compiler\FastlyServiceDecorationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class KaliopImageBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new FastlyServiceDecorationPass());
    }
}
