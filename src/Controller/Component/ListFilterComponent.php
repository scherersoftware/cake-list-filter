<?php
class ListFilterComponent extends Component {
	// Einstellungen
	public $settings = array();
	
	// Referenz auf den aufrufenden Controller
	private $Controller;
	
	public function initialize(Controller $controller, $settings = array()) {
		$this->settings = Set::merge($this->settings, $settings);
		$this->Controller = $controller;
	}

	public $defaultListFilter = array(
		// Typ des Eingabefelds
		'type' => 'text', 
		// Wenn es ein SELECT ist, dann hier die möglichen Werte als K=>V einfügen
		'options' => array(),
		// Formularfeld anzeigen. Bei Specials dieses einfach auf false setzen
		'showFormField' => true,
		// In Selects auch einen leeren Eintrag anzeigen
		'empty' => true,
		// Wenn der Wert mit einem speziellen DB-Feld verglichen werden soll (z.B. 'DATE(Log.created)')
		'conditionField' => '',
		// Zusätzliche Optionen für $this->Form->input(). Bei betweenDates können getrennte Optionen für from/to übergeben werden
		'inputOptions' => array(),
		'searchType' => 'wildcard'
	);

	public function startup(Controller $controller) {
		if(isset($this->Controller->listFilters[$this->Controller->action])) {
			$this->listFilters = $this->getFilters();


			// POST-Daten in URL umwandeln und weiterleiten
			if(!empty($this->Controller->data['Filter'])) {
				$urlParams = array();
				foreach($this->Controller->data['Filter'] as $model => $fields) {
					foreach($fields as $field => $value) {
						if(is_array($value)) {
							$value = "{$value['year']}-{$value['month']}-{$value['day']}";
							if($value == '--') continue;
						}
						$value = trim($value);
						if($value !== 0 && $value !== '0' && empty($value)) {
							continue;
						}
						$urlParams["Filter.{$model}.{$field}"] = $value;
					}
				}
				$this->Controller->redirect(Router::url($urlParams));
			}
			// Filtereinstellungen aus URL aufbereiten
			$filterActive = false;
			if(!empty($this->Controller->passedArgs)) {
				$filters = array();
				foreach($this->Controller->passedArgs as $arg => $value) {
					if(substr($arg, 0, 7) == 'Filter.') {
						unset($betweenDate);
						list($filter, $model, $field) = explode('.', $arg);

						if(substr($arg, -1) == ']') {
							if(preg_match('/^(.*)\[\d+\]$/', $arg, $matches)) {
								$fieldArg = $matches[1];
								$value = array();
								foreach($this->Controller->passedArgs as $a2 => $v2) {
									if(substr($a2, 0, strlen($fieldArg)) == $fieldArg) {
										$value[] = $v2;
									}
								}
								list($filter, $model, $field) = explode('.', $fieldArg);
							}
						}
						// if betweenDate
						if(preg_match("/([a-z_\-\.]+)_(from|to)$/i", $field, $matches)) {
							$betweenDate = $matches[2];
							$field = $matches[1];
						}
						if(isset($this->listFilters['fields']["{$model}.{$field}"])) {
							$options = $this->listFilters['fields']["{$model}.{$field}"];
							if(is_string($value)) {
								$value = trim($value);
							}

							// Der Wert, der ins Formularfeld kommt
							$viewValue = $value;
							$conditionField = "{$model}.{$field}";

							// Wenn der Wert leer ist, rausnehmen
							if(empty($value) && $value != 0) {
								continue;
							}
							// Wenn der Wert nicht in den erlaubten Werten definiert ist
							if($options['searchType'] != 'multipleselect' && !empty($options['options']) && !isset($options['options'][$value])) {
								continue;
							}
							// Wenn wildcards erlaubt sind, dann LIKE-Condition
							$fulltextSearch = false;
							if($options['searchType'] == 'wildcard') {
								//fulltext search
								if(isset($options['searchFields']) && is_array($options['searchFields'])){
									$fulltextSearch = true;
									$filters = [];
									foreach($options['searchFields'] as $searchField) {
										$filters['OR'][] = ["{$searchField} LIKE" => "%{$value}%"];
									}
								} else {
									$value = "%{$value}%";
									$value = str_replace('*', '%', $value);
									$conditionField = $conditionField . ' LIKE';
								}
							} 
							// Zwischen 2 Daten suchen
							else if($options['searchType'] == 'betweenDates') {
								$conditionField = 'DATE(' . $conditionField . ')';
								if($betweenDate == 'from') {
									$operator = '>=';
									#$this->Controller->data['Filter'][$model][$field . '_to'] = '';
								} else if($betweenDate == 'to') {
									$operator = '<=';
									#$this->Controller->data['Filter'][$model][$field . '_from'] = '';
								}
								if(!empty($options['conditionField'])) {
									$conditionField = $options['conditionField'];
								}
								$conditionField.= ' ' . $operator;

								// Workaround für FormHelper-Notices (Ticket #218)
								$otherKey = $betweenDate == 'from' ? '_to' : '_from';
								if(empty($this->Controller->data['Filter'][$model][$field . $otherKey])) {
									// $this->Controller->data['Filter'][$model][$field . $otherKey] = array('year' => null, 'month' => null, 'day' => null);
								}

								list($year, $month, $day) = explode('-', $value);
								$viewValue = compact('year', 'month', 'day');
								$field.= '_' . $betweenDate;
							}
							else if($options['searchType'] == 'afterDate') {
								$conditionField .= ' >=';
								list($year, $month, $day) = explode('-', $value);
								$viewValue = compact('year', 'month', 'day');
							}
							if(!$fulltextSearch) {
								$filters[$conditionField] = $value;
							}
							$this->Controller->request->data['Filter'][$model][$field] = $viewValue;
						}
					}
				}
				$filterActive = !empty($filters);
				$conditions = isset($this->Controller->paginate['conditions']) ? $this->Controller->paginate['conditions'] : array();

				$this->Controller->Paginator->settings = Hash::merge($this->Controller->Paginator->settings, [
					'conditions' => Set::merge($conditions, $filters)
				]);
			}

			foreach($this->listFilters['fields'] as $field => $options) {
				// Workaround, da Set::merge numerisch indizierte Arrays, wie die von find(list), neu indiziert
				if(!empty($this->listFilters['fields'][$field]['options'])) {
					$tmpOptions = $this->listFilters['fields'][$field]['options'];
				}
				$this->listFilters['fields'][$field] = Set::merge($this->defaultListFilter, $options);
				if(isset($tmpOptions)) {
					$this->listFilters['fields'][$field]['options'] = $tmpOptions;
				}
				unset($tmpOptions);
			}
			$this->Controller->set('filters', $this->listFilters['fields']);
			$this->Controller->set('filterActive', $filterActive);
		}
	}

/**
 * Formats and enriches field configs
 * @return array 
 */
	public function getFilters() {
		$filters = $this->Controller->listFilters[$this->Controller->action];
		foreach($filters['fields'] as $field => &$fieldConfig) {
			if(isset($fieldConfig['type']) && $fieldConfig['type'] == 'select' && !isset($fieldConfig['searchType'])) {
				$fieldConfig['searchType'] = 'select';
			}
		}
		return $filters;
	}
}
?>