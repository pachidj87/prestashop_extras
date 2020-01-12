<?php
namespace DSIELAB\Prestashop\Extras\Traits;

use PrestaShop\PrestaShop\Adapter\Entity\Cache;
use PrestaShop\PrestaShop\Adapter\Entity\Configuration;
use PrestaShop\PrestaShop\Adapter\Entity\Db;
use PrestaShop\PrestaShop\Adapter\Entity\ObjectModel;
use PrestaShop\PrestaShop\Adapter\Entity\PrestaShopCollection;
use PrestaShop\PrestaShop\Adapter\Entity\PrestaShopException;
use PrestaShop\PrestaShop\Adapter\Entity\Tools;
use PrestaShop\PrestaShop\Adapter\Entity\Shop;
use PrestaShop\PrestaShop\Adapter\Entity\Validate;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use WebserviceRequest;
use PrestaShopDatabaseException;
use ReflectionClass;

/**
 * Trait ObjectModelExtraTrait
 * @package ZumbIn\Core\Traits
 */
trait ObjectModelExtraTrait {
    /**
     * @var bool
     */
    public static $full_load = false;

    /**
     * @var array|bool
     */
	public $def_extra;

    /**
     * @var
     */
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
     * @throws \ReflectionException
     */
	public function __construct($id = null, $id_lang = null, $id_shop = null, $translator = null) {
		parent::__construct($id, $id_lang, $id_shop, $translator);

		$reflexion = new ReflectionClass(get_class($this));

		if (!$reflexion->hasProperty('definition_extra')) {
		    return;
        }

		$class_name = get_class($this);
		// Loading extra definitions
		if (isset(ObjectModel::$loaded_classes[$class_name]) && $this->def_extra === null) {
			$this->def_extra = self::getDefinitionExtra($class_name);
			if (isset($this->def_extra['primary']) && (!Validate::isTableOrIdentifier($this->def_extra['primary']) || !Validate::isTableOrIdentifier($this->def_extra['table']))) {
				throw new PrestaShopException('Identifier or table format not valid for class '.$class_name.' in extra fields');
			}

			ObjectModel::$loaded_classes[$class_name] = get_object_vars($this);
		} else {
			foreach (ObjectModel::$loaded_classes[$class_name] as $key => $value) {
				$this->{$key} = $value;
			}
		}

		if ($id) {
			$entity_mapper = ServiceLocator::get("\\PrestaShop\\PrestaShop\\Adapter\\EntityMapper");
            // Update object data
            $cache_id = 'objectmodel_' . $this->def_extra['classname'] . '_' . (int) $id . '_' . (int) $id_shop . '_' . (int) $id_lang;
            if (Cache::isStored($cache_id)) {
                Cache::clean($cache_id);
            }
			$entity_mapper->load($id, $id_lang, $this, $this->def_extra, $id_shop, self::$cache_objects && self::$full_load);
			self::$full_load = true;
		}

		$this->is_virtual = true;
	}

    /**
     * Returns object definition
     *
     * @param string $class Name of object
     * @param string|null $field Name of field if we want the definition of one field only
     *
     * @return array|bool
     * @throws \ReflectionException
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
		if (isset($this->def_extra['primary']) && !$fields && isset($this->id) && Validate::isUnsignedId($this->id)) {
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
	    if (isset($this->def_extra['primary'])) {
            foreach ($this->def_extra['fields'] as $field => $data) {
                if (!empty($data['lang'])) {
                    continue;
                }

                if (is_array($this->update_fields_extra) && empty($this->update_fields_extra[$field]) && isset($this->def_extra['fields'][$field]['shop']) && $this->def_extra['fields'][$field]['shop']) {
                    continue;
                }

                $message = $this->validateFieldExtra($field, $this->$field);
                if ($message !== true) {
                    if ($die) {
                        throw new PrestaShopException($message);
                    }
                    return $error_return ? $message : false;
                }
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

		if (!isset($this->def_extra['primary'])) {
		    return $fields;
        }

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

		if (isset($this->def_extra['primary']) && (!$result &= Db::getInstance()->insert($this->def_extra['table'], $this->getFieldsExtra(), $null_values))) {
			return false;
		}

		return $result;
	}

	/**
	 * Updates the current object in the database
	 *
	 * @param bool $null_values
	 *
	 * @return bool Insertion result
	 * @throws PrestaShopException
	 * @throws \PrestaShopDatabaseException
	 */
	public function update($null_values = false) {
		$result = parent::update($null_values);

		if (isset($this->def_extra['primary']) && (!$result &= Db::getInstance()->update($this->def_extra['table'], $this->getFieldsExtra(), '`'.pSQL($this->def_extra['primary']).'` = '.(int)$this->id, 0, $null_values))) {
			return false;
		}

		return $result;
	}

	/**
	 * Deletes current object from database
	 *
	 * @return bool True if delete was successful
	 * @throws PrestaShopException
	 */
	public function delete() {
		$result = parent::delete();

		if (isset($this->def_extra['primary']) && (!$result &= Db::getInstance()->delete($this->def_extra['table'], '`'.bqSQL($this->def['primary']).'` = '.(int)$this->id))) {
			return false;
		}

		return $result;
	}

	/**
	 * Validate a single field
	 *
	 * @since 1.5.0.1
	 * @param string   $field        Field name
	 * @param mixed    $value        Field value
	 * @param int|null $id_lang      Language ID
	 * @param array    $skip         Array of fields to skip.
	 * @param bool     $human_errors If true, uses more descriptive, translatable error strings.
	 *
	 * @return true|string True or error message string.
	 * @throws PrestaShopException
	 */
	public function validateFieldExtra($field, $value, $id_lang = null, $skip = array(), $human_errors = false)
	{
	    if (!isset($this->def_extra['fields'])) {
	        return true;
        }

		static $ps_lang_default = null;
		static $ps_allow_html_iframe = null;

		if ($ps_lang_default === null) {
			$ps_lang_default = Configuration::get('PS_LANG_DEFAULT');
		}

		if ($ps_allow_html_iframe === null) {
			$ps_allow_html_iframe = (int)Configuration::get('PS_ALLOW_HTML_IFRAME');
		}


		$this->cacheFieldsRequiredDatabase();
		$data = $this->def_extra['fields'][$field];



		// Check if field is required
		$required_fields = (isset(self::$fieldsRequiredDatabase[get_class($this)])) ? self::$fieldsRequiredDatabase[get_class($this)] : array();
		if (!$id_lang || $id_lang == $ps_lang_default) {
			if (!in_array('required', $skip) && (!empty($data['required']) || in_array($field, $required_fields))) {
				if (Tools::isEmpty($value)) {
					if ($human_errors) {
						return $this->trans('The %s field is required.', array($this->displayFieldName($field, get_class($this))), 'Admin.Notifications.Error');
					} else {
						return $this->trans('Property %s is empty.', array(get_class($this).'->'.$field), 'Admin.Notifications.Error');
					}
				}
			}
		}

		// Default value
		if (!$value && !empty($data['default'])) {
			$value = $data['default'];
			$this->$field = $value;
		}

		// Check field values
		if (!in_array('values', $skip) && !empty($data['values']) && is_array($data['values']) && !in_array($value, $data['values'])) {
			return $this->trans('Property %1$s has a bad value (allowed values are: %2$s).', array(get_class($this).'->'.$field, implode(', ', $data['values'])), 'Admin.Notifications.Error');
		}

		// Check field size
		if (!in_array('size', $skip) && !empty($data['size'])) {
			$size = $data['size'];
			if (!is_array($data['size'])) {
				$size = array('min' => 0, 'max' => $data['size']);
			}

			$length = Tools::strlen($value);
			if ($length < $size['min'] || $length > $size['max']) {
				if ($human_errors) {
					if (isset($data['lang']) && $data['lang']) {
						$language = new Language((int)$id_lang);
						return $this->trans('Your entry in field %1$s (language %2$s) exceeds max length %3$d chars (incl. html tags).', array($this->displayFieldName($field, get_class($this)), $language->name, $size['max']), 'Admin.Notifications.Error');
					} else {
						return $this->trans('The %1$s field is too long (%2$d chars max).', array($this->displayFieldName($field, get_class($this)), $size['max']), 'Admin.Notifications.Error');
					}
				} else {
					return $this->trans('The length of property %1$s is currently %2$d chars. It must be between %3$d and %4$d chars.',
						array(
							get_class($this).'->'.$field,
							$length,
							$size['min'],
							$size['max'],
						),
						'Admin.Notifications.Error'
					);
				}
			}
		}

		// Check field validator
		if (!in_array('validate', $skip) && !empty($data['validate'])) {
			if (!method_exists('Validate', $data['validate'])) {
				throw new PrestaShopException(
					$this->trans(
						'Validation function not found: %s.',
						array($data['validate']),
						'Admin.Notifications.Error'
					)
				);
			}

			if (!empty($value)) {
				$res = true;
				if (Tools::strtolower($data['validate']) == 'iscleanhtml') {
					if (!call_user_func(array('Validate', $data['validate']), $value, $ps_allow_html_iframe)) {
						$res = false;
					}
				} else {
					if (!call_user_func(array('Validate', $data['validate']), $value)) {
						$res = false;
					}
				}
				if (!$res) {
					if ($human_errors) {
						return $this->trans('The %s field is invalid.', array($this->displayFieldName($field, get_class($this))), 'Admin.Notifications.Error');
					} else {
						return $this->trans('Property %s is not valid', array(get_class($this).'->'.$field), 'Admin.Notifications.Error');
					}
				}
			}
		}

		return true;
	}

    /**
     * Returns webservice parameters of this object.
     *
     * @param string|null $ws_params_attribute_name
     *
     * @return array
     */
    public function getWebserviceParameters($ws_params_attribute_name = null) {
        $resource_parameters = parent::getWebserviceParameters($ws_params_attribute_name);
        $required_fields = $this->getCachedFieldsRequiredDatabase();

        foreach ($this->def_extra['fields'] as $field_name => $details) {
            if (!isset($resource_parameters['fields'][$field_name])) {
                $resource_parameters['fields'][$field_name] = array();
            }
            $current_field = array();
            $current_field['sqlId'] = $field_name;
            if (isset($details['size'])) {
                $current_field['maxSize'] = $details['size'];
            }
            if (isset($details['lang'])) {
                $current_field['i18n'] = $details['lang'];
            } else {
                $current_field['i18n'] = false;
            }
            if ((isset($details['required']) && $details['required'] === true) || in_array($field_name, $required_fields)) {
                $current_field['required'] = true;
            } else {
                $current_field['required'] = false;
            }
            if (isset($details['validate'])) {
                $current_field['validateMethod'] = (
                array_key_exists('validateMethod', $resource_parameters['fields'][$field_name]) ?
                    array_merge($resource_parameters['fields'][$field_name]['validateMethod'], array($details['validate'])) :
                    array($details['validate'])
                );
            }
            $resource_parameters['fields'][$field_name] = array_merge($resource_parameters['fields'][$field_name], $current_field);

            if (isset($details['ws_modifier'])) {
                $resource_parameters['fields'][$field_name]['modifier'] = $details['ws_modifier'];
            }
        }

        return $resource_parameters;
    }

    /**
     * Returns webservice object list.
     *
     * @param string $sql_join
     * @param string $sql_filter
     * @param string $sql_sort
     * @param string $sql_limit
     *
     * @return array|null
     *
     * @throws PrestaShopDatabaseException
     */
    public function getWebserviceObjectList($sql_join, $sql_filter, $sql_sort, $sql_limit)
    {
        $assoc = Shop::getAssoTable($this->def['table']);
        $assoc_extra = isset($this->def_extra) ? $this->def_extra['table'] : false;

        if ($assoc_extra) {
            $sql_join .= ' LEFT JOIN `'._DB_PREFIX_ . bqSQL($this->def_extra['table']) .'` AS `main_extra`';
        }

        $class_name = WebserviceRequest::$ws_current_classname;
        $vars = get_class_vars($class_name);
        if ($assoc !== false) {
            if ($assoc['type'] !== 'fk_shop') {
                $multi_shop_join = ' LEFT JOIN `' . _DB_PREFIX_ . bqSQL($this->def['table']) . '_' . bqSQL($assoc['type']) . '`
										AS `multi_shop_' . bqSQL($this->def['table']) . '`
										ON (main.`' . bqSQL($this->def['primary']) . '` = `multi_shop_' . bqSQL($this->def['table']) . '`.`' . bqSQL($this->def['primary']) . '`)';
                $sql_filter = 'AND `multi_shop_' . bqSQL($this->def['table']) . '`.id_shop = ' . Context::getContext()->shop->id . ' ' . $sql_filter;
                $sql_join = $multi_shop_join . ' ' . $sql_join;
            } else {
                $vars = get_class_vars($class_name);
                foreach ($vars['shopIDs'] as $id_shop) {
                    $or[] = '(main.id_shop = ' . (int) $id_shop . (isset($this->def['fields']['id_shop_group']) ? ' OR (id_shop = 0 AND id_shop_group=' . (int) Shop::getGroupFromShop((int) $id_shop) . ')' : '') . ')';
                }

                $prepend = '';
                if (count($or)) {
                    $prepend = 'AND (' . implode('OR', $or) . ')';
                }
                $sql_filter = $prepend . ' ' . $sql_filter;
            }
        }

        $query = '
		SELECT DISTINCT main.`' . bqSQL($this->def['primary']) . '` FROM `' . _DB_PREFIX_ . bqSQL($this->def['table']) . '` AS main
		' . $sql_join . '
		WHERE 1 ' . $sql_filter . '
		' . ($sql_sort != '' ? $sql_sort : '') . '
		' . ($sql_limit != '' ? $sql_limit : '');

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
    }
}
