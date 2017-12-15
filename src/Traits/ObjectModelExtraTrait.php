<?php
/**
 * Created by PhpStorm.
 * User: ahechevarria
 * Date: 3/12/17
 * Time: 23:11
 */

namespace DSIELAB\Prestashop\Extras\Traits;

use PrestaShop\PrestaShop\Adapter\Entity\Cache;
use PrestaShop\PrestaShop\Adapter\Entity\Configuration;
use PrestaShop\PrestaShop\Adapter\Entity\Db;
use PrestaShop\PrestaShop\Adapter\Entity\ObjectModel;
use PrestaShop\PrestaShop\Adapter\Entity\PrestaShopCollection;
use PrestaShop\PrestaShop\Adapter\Entity\PrestaShopException;
use PrestaShop\PrestaShop\Adapter\Entity\Tools;
use PrestaShop\PrestaShop\Adapter\Entity\Validate;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use ReflectionClass;

/**
 * Trait ObjectModelExtraTrait
 * @package ZumbIn\Core\Traits
 */
trait ObjectModelExtraTrait {

	public static $definition_extra;
	public $def_extra;
	public $update_fields_extra;

	/**
	 * ObjectModelExtraTrait constructor.
	 *
	 * @param null $id
	 * @param null $id_lang
	 * @param null $id_shop
	 * @param null $translator
	 *
	 * @throws PrestaShopException
	 * @throws \PrestaShop\PrestaShop\Adapter\CoreException
	 */
	public function __construct($id = null, $id_lang = null, $id_shop = null, $translator = null) {
		parent::__construct($id, $id_lang, $id_shop, $translator);

		$class_name = get_class($this);
		if (!isset(self::$loaded_classes[$class_name])) {
			$this->def_extra = self::getDefinitionExtra($class_name);
			if (!Validate::isTableOrIdentifier($this->def_extra['primary']) || !Validate::isTableOrIdentifier($this->def_extra['table'])) {
				throw new PrestaShopException('Identifier or table format not valid for class '.$class_name.' in extra fields');
			}

			self::$loaded_classes[$class_name] = get_object_vars($this);
		} else {
			foreach (self::$loaded_classes[$class_name] as $key => $value) {
				$this->{$key} = $value;
			}
		}

		if ($id) {
			$entity_mapper = ServiceLocator::get("\\PrestaShop\\PrestaShop\\Adapter\\EntityMapper");
			$entity_mapper->load($id, $id_lang, $this, $this->def_extra, $this->id_shop, self::$cache_objects);
		}

		$this->is_virtual = true;
	}

	/**
	 * Returns object definition
	 *
	 * @param string      $class Name of object
	 * @param string|null $field Name of field if we want the definition of one field only
	 *
	 * @return array|bool
	 */
	public static function getDefinitionExtra($class, $field = null) {
		if (is_object($class)) {
			$class = get_class($class);
		}

		if ($field === null) {
			$cache_id = 'objectmodel_def_extra_'.$class;
		}

		if ($field !== null || !Cache::isStored($cache_id)) {
			$reflection = new ReflectionClass($class);

			if (!$reflection->hasProperty('definition_extra')) {
				return false;
			}

			$definition_extra = $reflection->getStaticPropertyValue('definition_extra');

			$definition_extra['classname'] = $class;

			if (!empty($definition_extra['multilang'])) {
				$definition_extra['associations'][PrestaShopCollection::LANG_ALIAS] = array(
					'type' => self::HAS_MANY,
					'field' => $definition_extra['primary'],
					'foreign_field' => $definition_extra['primary'],
				);
			}

			if ($field) {
				return isset($definition_extra['fields'][$field]) ? $definition_extra['fields'][$field] : null;
			}

			Cache::store($cache_id, $definition_extra);
			return $definition_extra;
		}

		return Cache::retrieve($cache_id);
	}

	/**
	 * Prepare fields for ObjectModel class (add, update)
	 * All fields are verified (pSQL, intval, ...)
	 *
	 * @return array All object fields
	 * @throws PrestaShopException
	 */
	public function getFieldsExtra()
	{
		$this->validateFieldsExtra();
		$fields = $this->formatFieldsExtra(self::FORMAT_COMMON);

		// Ensure that we get something to insert
		if (!$fields && isset($this->id) && Validate::isUnsignedId($this->id)) {
			$fields[$this->def_extra['primary']] = $this->id;
		}

		return $fields;
	}

	/**
	 * Checks if object field values are valid before database interaction
	 *
	 * @param bool $die
	 * @param bool $error_return
	 *
	 * @return bool|string True, false or error message.
	 * @throws PrestaShopException
	 */
	public function validateFieldsExtra($die = true, $error_return = false)
	{
		foreach ($this->def_extra['fields'] as $field => $data) {
			if (!empty($data['lang'])) {
				continue;
			}

			if (is_array($this->update_fields_extra) && empty($this->update_fields_extra[$field]) && isset($this->def_extra['fields'][$field]['shop']) && $this->def_extra['fields'][$field]['shop']) {
				continue;
			}

			$message = $this->validateFieldsExtra($field, $this->$field);
			if ($message !== true) {
				if ($die) {
					throw new PrestaShopException($message);
				}
				return $error_return ? $message : false;
			}
		}

		return true;
	}

	/**
	 * Set a list of specific fields to update
	 * array(field1 => true, field2 => false,
	 * langfield1 => array(1 => true, 2 => false))
	 *
	 * @since 1.5.0.1
	 * @param array $fields
	 */
	public function setFieldsToUpdateExtra(array $fields)
	{
		$this->update_fields_extra = $fields;
	}

	/**
	 * Formats values of each fields.
	 *
	 * @since 1.5.0.1
	 * @param int $type    FORMAT_COMMON or FORMAT_LANG or FORMAT_SHOP
	 * @param int $id_lang If this parameter is given, only take lang fields
	 *
	 * @return array
	 */
	protected function formatFieldsExtra($type, $id_lang = null)
	{
		$fields = array();

		// Set primary key in fields
		if (isset($this->id)) {
			$fields[$this->def_extra['primary']] = $this->id;
		}

		foreach ($this->def_extra['fields'] as $field => $data) {
			// Only get fields we need for the type
			// E.g. if only lang fields are filtered, ignore fields without lang => true
			if (($type == self::FORMAT_LANG && empty($data['lang']))
			    || ($type == self::FORMAT_SHOP && empty($data['shop']))
			    || ($type == self::FORMAT_COMMON && ((!empty($data['shop']) && $data['shop'] != 'both') || !empty($data['lang'])))) {
				continue;
			}

			if (is_array($this->update_fields)) {
				if ((!empty($data['lang']) || (!empty($data['shop']) && $data['shop'] != 'both')) && (empty($this->update_fields[$field]) || ($type == self::FORMAT_LANG && empty($this->update_fields[$field][$id_lang])))) {
					continue;
				}
			}

			// Get field value, if value is multilang and field is empty, use value from default lang
			$value = $this->$field;
			if ($type == self::FORMAT_LANG && $id_lang && is_array($value)) {
				if (!empty($value[$id_lang])) {
					$value = $value[$id_lang];
				} elseif (!empty($data['required'])) {
					$value = $value[Configuration::get('PS_LANG_DEFAULT')];
				} else {
					$value = '';
				}
			}

			$purify = (isset($data['validate']) && Tools::strtolower($data['validate']) == 'iscleanhtml') ? true : false;
			// Format field value
			$fields[$field] = ObjectModel::formatValue($value, $data['type'], false, $purify, !empty($data['allow_null']));
		}

		return $fields;
	}

	/**
	 * Adds current object to the database
	 *
	 * @param bool $auto_date
	 * @param bool $null_values
	 *
	 * @return bool Insertion result
	 * @throws PrestaShopException
	 * @throws \PrestaShopDatabaseException
	 */
	public function add($auto_date = true, $null_values = false) {
		$result = parent::add($auto_date, $null_values);

		if (!$result &= Db::getInstance()->insert($this->def_extra['table'], $this->getFieldsExtra(), $null_values)) {
			return false;
		}

		return $result;
	}
}