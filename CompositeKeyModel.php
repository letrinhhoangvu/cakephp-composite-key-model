<?php

App::uses("AppModel", "Model");

class CompositeKeyModel extends AppModel {

	public function exists() {
		if ($this->getID() === false) {
			return false;
		}

		//check if exists by multiple primaryKey
		$conditions = array();
		foreach ($this->primaryKey as $key) {
			$conditions[$this->alias . '.' . $key] = $this->data[$this->alias][$key];
		}

		$query = array('conditions' => $conditions, 'recursive' => -1, 'callbacks' => false);
		return ($this->find('count', $query) > 0);
	}

	public function save($data = null, $validate = true, $fieldList = array()) {
		$defaults = array('validate' => true, 'fieldList' => array(), 'callbacks' => true);
		$_whitelist = $this->whitelist;
		$fields = array();

		if (!is_array($validate)) {
			$options = array_merge($defaults, compact('validate', 'fieldList', 'callbacks'));
		} else {
			$options = array_merge($defaults, $validate);
		}

		if (!empty($options['fieldList'])) {
			$this->whitelist = $options['fieldList'];
		} elseif ($options['fieldList'] === null) {
			$this->whitelist = array();
		}
		$this->set($data);

		if (empty($this->data) && !$this->hasField(array('created', 'updated', 'modified'))) {
			return false;
		}

		foreach (array('created', 'updated', 'modified') as $field) {
			$keyPresentAndEmpty = (isset($this->data[$this->alias]) && array_key_exists($field, $this->data[$this->alias]) && $this->data[$this->alias][$field] === null);
			if ($keyPresentAndEmpty) {
				unset($this->data[$this->alias][$field]);
			}
		}

		$exists = $this->exists();
		$dateFields = array('modified', 'updated');

		if (!$exists) {
			$dateFields[] = 'created';
		}
		if (isset($this->data[$this->alias])) {
			$fields = array_keys($this->data[$this->alias]);
		}
		if ($options['validate'] && !$this->validates($options)) {
			$this->whitelist = $_whitelist;
			return false;
		}

		$db = $this->getDataSource();

		foreach ($dateFields as $updateCol) {
			if ($this->hasField($updateCol) && !in_array($updateCol, $fields)) {
				$default = array('formatter' => 'date');
				$colType = array_merge($default, $db->columns[$this->getColumnType($updateCol)]);
				if (!array_key_exists('format', $colType)) {
					$time = strtotime('now');
				} else {
					$time = $colType['formatter']($colType['format']);
				}
				if (!empty($this->whitelist)) {
					$this->whitelist[] = $updateCol;
				}
				$this->set($updateCol, $time);
			}
		}

		if ($options['callbacks'] === true || $options['callbacks'] === 'before') {
			$result = $this->Behaviors->trigger('beforeSave', array(&$this, $options), array('break' => true, 'breakOn' => array(false, null)));
			if (!$result || !$this->beforeSave($options)) {
				$this->whitelist = $_whitelist;
				return false;
			}
		}

		/*if (empty($this->data[$this->alias][$this->primaryKey])) {
		    unset($this->data[$this->alias][$this->primaryKey]);
		}*/
		$fields = $values = array();

		foreach ($this->data as $n => $v) {
			if (isset($this->hasAndBelongsToMany[$n])) {
				if (isset($v[$n])) {
					$v = $v[$n];
				}
				$joined[$n] = $v;
			} else {
				if ($n === $this->alias) {
					foreach (array('created', 'updated', 'modified') as $field) {
						if (array_key_exists($field, $v) && empty($v[$field])) {
							unset($v[$field]);
						}
					}

					foreach ($v as $x => $y) {
						if ($this->hasField($x) && (empty($this->whitelist) || in_array($x, $this->whitelist))) {
							list($fields[], $values[]) = array($x, $y);
						}
					}
				}
			}
		}
		$count = count($fields);

		if (!$exists && $count > 0) {
			$this->id = false;
		}
		$success = true;
		$created = false;

		/*if ($count > 0) {
		    $cache = $this->_prepareUpdateFields(array_combine($fields, $values));
		
		    if (!empty($this->id)) {
		        $success = (bool) $db->update($this, $fields, $values);
		    } else {
		        $fInfo = $this->schema($this->primaryKey);
		        $isUUID = ($fInfo['length'] == 36 && ($fInfo['type'] === 'string' || $fInfo['type'] === 'binary'));
		        if (empty($this->data[$this->alias][$this->primaryKey]) && $isUUID) {
		            if (array_key_exists($this->primaryKey, $this->data[$this->alias])) {
		                $j = array_search($this->primaryKey, $fields);
		                $values[$j] = String::uuid();
		            } else {
		                list($fields[], $values[]) = array($this->primaryKey, String::uuid());
		            }
		        }
		
		        if (!$db->create($this, $fields, $values)) {
		            $success = $created = false;
		        } else {
		            $created = true;
		        }
		    }
		
		    if ($success && !empty($this->belongsTo)) {
		        $this->updateCounterCache($cache, $created);
		    }
		}*/

		if ($count > 0) {
			foreach ($values as $key => &$field) {
				$field = $db->value($field);
			}
			$conditions = array();
			foreach ($this->primaryKey as $key) {
				$conditions["{$this->alias}.{$key}"] = isset($this->data[$this->alias][$key]) ? $this->data[$this->alias][$key] : null;
			}

			$db->update($this, $fields, $values, $conditions);
		} else {
			$db->create($this, $fields, $values);
		}

		//disable save Multi
		/*if (!empty($joined) && $success === true) {
		    $this->_saveMulti($joined, $this->id, $db);
		}*/

		if ($success && $count > 0) {
			if (!empty($this->data)) {
				$success = $this->data;
				if ($created) {
					$this->data[$this->alias][$this->primaryKey] = $this->id;
				}
			}
			if ($options['callbacks'] === true || $options['callbacks'] === 'after') {
				$this->Behaviors->trigger('afterSave', array(&$this, $created, $options));
				$this->afterSave($created);
			}
			if (!empty($this->data)) {
				$success = Set::merge($success, $this->data);
			}
			$this->data = false;
			$this->_clearCache();
			$this->validationErrors = array();
		}
		$this->whitelist = $_whitelist;
		return $success;
	}

	/*public function save($data = null, $validate = true, $fieldList = array()) {
	    $db = &ConnectionManager::getDataSource($this->useDbConfig);
	
	    $fields = $values = array();
	
	    foreach ($this->data as $n => $v) {
	        if (isset($this->hasAndBelongsToMany[$n])) {
	            if (isset($v[$n])) {
	                $v = $v[$n];
	            }
	            $joined[$n] = $v;
	        } else {
	            if ($n === $this->alias) {
	                foreach (array('created', 'updated', 'modified') as $field) {
	                    if (array_key_exists($field, $v) && empty($v[$field])) {
	                        unset($v[$field]);
	                    }
	                }
	
	                foreach ($v as $x => $y) {
	                    if ($this->hasField($x) && (empty($this->whitelist) || in_array($x, $this->whitelist))) {
	                        list($fields[], $values[]) = array($x, $y);
	                    }
	                }
	            }
	        }
	    }
	
	    if ($this->id) {
	        foreach ($values as $key => &$field) {
	            $field = $db->value($field);
	        }
	        $conditions = array();
	        foreach ($this->primaryKey as $key) {
	            //todo: for logs, we need to fix this
	            $conditions["{$this->alias}.{$key}"] = empty($this->data[$this->alias][$key]) ? "" : $this->data[$this->alias][$key];
	        }
	        print_r(array($fields, $values, $conditions));
	
	        $db->update($this, $fields, $values, $conditions);
	    } else {
	        $db->create($this, $fields, $values);
	    }
	}*/

}

?>