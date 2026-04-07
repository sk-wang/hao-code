<?php

namespace App\Support\Container;

use DI\Container;
use DI\ContainerBuilder;

/**
 * Builds the PHP-DI container from the definitions file.
 */
class ContainerFactory
{
    public static function create(): Container
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/container-definitions.php');
        $builder->useAutowiring(true);

        return $builder->build();
    }
}
