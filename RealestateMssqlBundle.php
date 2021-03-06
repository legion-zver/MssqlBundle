<?php

/*
 * This file is part of the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Realestate\MssqlBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Doctrine\DBAL\Types\Type;

class RealestateMssqlBundle extends Bundle
{
    public function boot()
    {
        if("mssql" == $driver = $this->container->getParameter('database_type')) {
            Type::overrideType('string', 'Realestate\MssqlBundle\Types\StringType');
            Type::overrideType('text', 'Realestate\MssqlBundle\Types\TextType');

            // Register custom data types
            if (!Type::hasType('uniqueidentifier')) {
                Type::addType('uniqueidentifier', 'Realestate\MssqlBundle\Types\UniqueidentifierType');
            }

            if (!Type::hasType('geography')) {
                Type::addType('geography', 'Realestate\MssqlBundle\Types\PointType');
            }

            Type::overrideType('date', 'Realestate\MssqlBundle\Types\DateType');
            Type::overrideType('datetime', 'Realestate\MssqlBundle\Types\DateTimeType');
        }
    }
}
