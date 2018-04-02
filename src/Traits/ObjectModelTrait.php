<?php
namespace DSIELAB\Prestashop\Extras\Traits;

use PrestaShop\PrestaShop\Adapter\Entity\Db;

trait ObjectModelTrait
{
    /**
     * Retrieve deposit id for product if exist
     *
     * @param $id_product
     * @return false|null|string
     */
    public static function getForProduct($id_product)
    {
        return Db::getInstance()->getValue('
            SELECT `'.self::$definition['primary'].'` 
            FROM `'._DB_PREFIX_.self::$definition['table'].'` 
            WHERE `id_product` = '.(int)$id_product
        );
    }
}