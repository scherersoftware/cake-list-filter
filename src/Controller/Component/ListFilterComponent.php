<?php
namespace ListFilter\Controller\Component;

use Cake\Controller\Component;
use Cake\Routing\Router;
use Cake\Utility\Hash;

class ListFilterComponent extends Component {

	public $components = ['Paginator'];

    public $filterActive = false;

	protected $_controller;

	public $defaultListFilter = array(
		'type' => 'text',
		'options' => array(),
		'showFormField' => true,
		'empty' => true,
		'conditionField' => '',
		'inputOptions' => array(),
		'searchType' => 'wildcard'
	);

/**
 * Initializes the instance
 *
 * @param array $config Component configuration
 * @return void
 */
	public function initialize(array $config) {
	}

/**
 * Startup callback
 *
 * @param Event $event Controller::startup event
 * @return void
 */
	public function startup(\Cake\Event\Event $event) {
		$this->_controller = $event->subject();
		$controllerListFilters = $this->getFilters();

		if (!empty($controllerListFilters)) {
			$this->listFilters = $this->getFilters();

			// PRG
			if ($this->_controller->request->is('post') && !empty($this->_controller->request->data['Filter'])) {
				$urlParams = array();
				foreach ($this->_controller->request->data['Filter'] as $model => $fields) {
					foreach ($fields as $field => $value) {
						if (is_array($value)) {
							$value = "{$value['year']}-{$value['month']}-{$value['day']}";
							if ($value == '--') {
								continue;
							}
						}
						$value = trim($value);
						if ($value !== 0 && $value !== '0' && empty($value)) {
							continue;
						}
						$urlParams["Filter-{$model}-{$field}"] = $value;
					}
				}
				return $this->_controller->redirect(Router::url($urlParams));
			}

			$this->filterActive = false;

			if (!empty($this->_controller->request->query)) {
				$filters = array();

				foreach ($this->_controller->request->query as $arg => $value) {
					if (substr($arg, 0, 7) == 'Filter-') {
						unset($betweenDate);
						list($filter, $model, $field) = explode('-', $arg);

						if (substr($arg, -1) == ']') {
							if (preg_match('/^(.*)\[\d+\]$/', $arg, $matches)) {
								$fieldArg = $matches[1];
								$value = array();
								foreach ($this->_controller->passedArgs as $a2 => $v2) {
									if (substr($a2, 0, strlen($fieldArg)) == $fieldArg) {
										$value[] = $v2;
									}
								}
								list($filter, $model, $field) = explode('.', $fieldArg);
							}
						}
						// if betweenDate
						if (preg_match("/([a-z_\-\.]+)_(from|to)$/i", $field, $matches)) {
							$betweenDate = $matches[2];
							$field = $matches[1];
						}
						if (isset($this->listFilters['fields']["{$model}.{$field}"])) {
							$options = Hash::merge([
								'searchType' => 'wildcard'
							], $this->listFilters['fields']["{$model}.{$field}"]);
							if (is_string($value)) {
								$value = trim($value);
							}

							$viewValue = $value;
							$conditionField = "{$model}.{$field}";

							if (empty($value) && $value != 0) {
								continue;
							}

							// Support for hierarchical arrays / optgroup selects
							$flatOptions = $options['options'];
							if(is_array(current($options['options']))) {
								$flatOptions = [];
								foreach($options['options'] as $group => $valueGroup) {
									$flatOptions = $flatOptions + $valueGroup;
								}
							}
							
							if (!empty($options['options']) && $options['searchType'] != 'multipleselect' && !isset($flatOptions[$value])) {
								continue;
							}

							$fulltextSearch = false;
							if ($options['searchType'] == 'wildcard') {
								//fulltext search
								if (isset($options['searchFields']) && is_array($options['searchFields'])) {
									$fulltextSearch = true;
									$filters = [];
									foreach ($options['searchFields'] as $searchField) {
										$filters['OR'][] = ["{$searchField} LIKE" => "%{$value}%"];
									}
								} else {
									$value = "%{$value}%";
									$value = str_replace('*', '%', $value);
									$conditionField = $conditionField . ' LIKE';
								}
							} elseif ($options['searchType'] == 'betweenDates') {
								$conditionField = 'DATE(' . $conditionField . ')';
								if ($betweenDate == 'from') {
									$operator = '>=';
									#$this->_controller->data['Filter'][$model][$field . '_to'] = '';
								} elseif ($betweenDate == 'to') {
									$operator = '<=';
									#$this->_controller->data['Filter'][$model][$field . '_from'] = '';
								}
								if (!empty($options['conditionField'])) {
									$conditionField = $options['conditionField'];
								}
								$conditionField .= ' ' . $operator;

								// Workaround für FormHelper-Notices (Ticket #218)
								$otherKey = $betweenDate == 'from' ? '_to' : '_from';
								if (empty($this->_controller->data['Filter'][$model][$field . $otherKey])) {
									// $this->_controller->data['Filter'][$model][$field . $otherKey] = array('year' => null, 'month' => null, 'day' => null);
								}

								list($year, $month, $day) = explode('-', $value);
								$viewValue = compact('year', 'month', 'day');
								$field .= '_' . $betweenDate;
							} elseif ($options['searchType'] == 'afterDate') {
								$conditionField .= ' >=';

								if (preg_match('/^[\d]{4}-[\d]{2}-[\d]{2}$/', $value)) {
									list($year, $month, $day) = explode('-', $value);
									$viewValue = compact('year', 'month', 'day');
								}
							}
							if (!$fulltextSearch) {
								$filters[$conditionField] = $value;
							}
							$this->_controller->request->data['Filter'][$model][$field] = $viewValue;
						}
					}
				}

				$this->filterActive = !empty($filters);
				$conditions = isset($this->_controller->paginate['conditions']) ? $this->_controller->paginate['conditions'] : [];

				$this->_controller->paginate = Hash::merge($this->_controller->paginate, [
					'conditions' => Hash::merge($conditions, $filters)
				]);
			}

			foreach ($this->listFilters['fields'] as $field => $options) {
				if (!empty($this->listFilters['fields'][$field]['options'])) {
					$tmpOptions = $this->listFilters['fields'][$field]['options'];
				}
				$this->listFilters['fields'][$field] = Hash::merge($this->defaultListFilter, $options);
				if (isset($tmpOptions)) {
					$this->listFilters['fields'][$field]['options'] = $tmpOptions;
				}
				unset($tmpOptions);
			}
			$this->_controller->set('filters', $this->listFilters['fields']);
			$this->_controller->set('filterActive', $this->filterActive);
		}
	}

/**
 * Formats and enriches field configs
 *
 * @return array 
 */
	public function getFilters() {
		if(method_exists($this->_controller, 'getListFilters')) {
			$filters = $this->_controller->getListFilters($this->_controller->request->action);
		} else {
			$filters = $this->_controller->listFilters[$this->_controller->request->action];
		}
		foreach ($filters['fields'] as $field => &$fieldConfig) {
			if (isset($fieldConfig['type']) && $fieldConfig['type'] == 'select' && !isset($fieldConfig['searchType'])) {
				$fieldConfig['searchType'] = 'select';
			}
		}
		return $filters;
	}
}