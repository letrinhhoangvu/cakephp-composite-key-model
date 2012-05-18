<?php

App::uses("AppModel","Model");

class CompositKeyModel extends AppModel {

	/**
	 * Method to finish behavior actions
	 */
	public function save($data = null, $validate = true, $fieldList = array()) {
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
				$conditions["{$this->alias}.{$key}"] = $key;
			}

			$db->update($this, $fields, $values, $conditions);
		} else {
			$db->create($this, $fields, $values);
		}
	}

}

?>