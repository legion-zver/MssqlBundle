<?php

namespace Realestate\MssqlBundle\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;

class StringType extends \Doctrine\DBAL\Types\StringType
{
    public function getName() {
        return self::STRING;
    }

    public function canRequireSQLConversion() {
        return true;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform) {
        if(is_null($value) || empty($value)) {
            return '';
        }
        return $value;
    }

    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform) {
        return sprintf("N%s", $sqlExpr);
    }
}