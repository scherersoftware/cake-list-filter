<?php
namespace ListFilter\Controller\Component;

use Cake\Controller\Component;
use Cake\Routing\Router;
use Cake\Utility\Hash;

/**
 * Todos
 * - make class configurable with InstanceConfigTrait
 * - consolidate naming of searchTypes, better default list filter config
 * - fix weird escaping of [] for array values in URLs
 *
 * @package default
 */

class ListFilterComponent extends Component
{

    /**
     * Controller Instance to work with
     *
     * @var Cake\Controller\Controller
     */
    protected $_controller;

    /**
     * Default array structure of a list filter.
     *
     * @var array
     */
    public $defaultListFilter = [
        'searchType' => 'wildcard',
        'inputOptions' => [
            'type' => 'text'
        ],
    ];

    /**
     * Initializes the instance
     *
     * @param array $config Component configuration
     * @return void
     */
    public function initialize(array $config)
    {
    }

    /**
     * Startup callback
     *
     * @param Event $event Controller::startup event
     * @return void
     */
    public function startup(\Cake\Event\Event $event)
    {
        $this->_controller = $event->subject();
        $controllerListFilters = $this->getFilters();

        if (empty($controllerListFilters)) {
            return;
        }
        if ($this->_controller->request->is('post') && !empty($this->_controller->request->data['Filter'])) {
            return $this->_controller->redirect($this->getRedirectUrlFromPostData($this->_controller->request->data));
        }
        $filterConditions = [];
        if (!empty($this->_controller->request->query)) {
            $filterConditions = $this->_prepareFilterConditions($controllerListFilters);
            $conditions = isset($this->_controller->paginate['conditions']) ? $this->_controller->paginate['conditions'] : [];
            $this->_controller->paginate = Hash::merge($this->_controller->paginate, [
                'conditions' => Hash::merge($conditions, $filterConditions)
            ]);
        }
        foreach ($controllerListFilters['fields'] as $field => $options) {
            if (!empty($controllerListFilters['fields'][$field]['options'])) {
                $tmpOptions = $controllerListFilters['fields'][$field]['options'];
            }
            $controllerListFilters['fields'][$field] = Hash::merge($this->defaultListFilter, $options);
            if (isset($tmpOptions)) {
                $controllerListFilters['fields'][$field]['options'] = $tmpOptions;
            }
            unset($tmpOptions);
        }
        $this->_controller->set('filters', $controllerListFilters['fields']);
        $this->_controller->set('filterActive', !empty($filterConditions));
    }

    /**
     * Whether the filter has actually manipulated the pagination conditions
     *
     * @return bool
     */
    public function filterActive()
    {
        return isset($this->_controller->viewVars['filterActive']) ? $this->_controller->viewVars['filterActive'] : false;
    }

    /**
     * Uses the query parameters to construct the $filters config array to be passed to the front end
     * and sets request data so form fields are pre-filled
     *
     * @param array $controllerListFilters ListFilter configuration
     * @return array
     */
    protected function _prepareFilterConditions(array $controllerListFilters)
    {
        $filterConditions = [];
        foreach ($this->_controller->request->query as $arg => $value) {
            if (substr($arg, 0, 7) != 'Filter-') {
                continue;
            }
            unset($betweenDate);
            list($filter, $model, $field) = explode('-', $arg);

            // if betweenDate
            if (preg_match("/([a-z_\-\.]+)_(from|to)$/i", $field, $matches)) {
                $betweenDate = $matches[2];
                $field = $matches[1];
            }
            $conditionField = "{$model}.{$field}";
            if (!isset($controllerListFilters['fields'][$conditionField])) {
                continue;
            }

            $options = $controllerListFilters['fields'][$conditionField];

            if (is_string($value)) {
                $value = trim($value);
            }

            if (empty($value) && $value != 0) {
                continue;
            }
            if (isset($options['options'])) {
                $validOptions = $this->_flattenValueOptions($options['options']);
            }
            // for non-multiselects, check if the value is present in the defined valid options
            if (!empty($options['options']) && $options['searchType'] != 'multipleselect' && !isset($validOptions[$value])) {
                continue;
            }
            // for multiselects, filter out values not defined in value options
            if ($options['searchType'] == 'multipleselect') {
                $value = array_intersect($value, array_keys($validOptions));
            }

            // value to be used for form fields
            $viewValue = $value;

            if ($options['searchType'] == 'wildcard') {
                $value = "%{$value}%";
                $conditionField = $conditionField . ' LIKE';
            } elseif ($options['searchType'] == 'fulltext') {
                $filterConditions[] = $this->_getFulltextSearchConditions($conditionField, $value, $options);
            } elseif ($options['searchType'] == 'betweenDates') {
                $conditionField = 'DATE(' . $conditionField . ')';
                if ($betweenDate == 'from') {
                    $operator = '>=';
                } elseif ($betweenDate == 'to') {
                    $operator = '<=';
                }
                if (!empty($options['conditionField'])) {
                    $conditionField = $options['conditionField'];
                }
                $conditionField .= ' ' . $operator;
                list($year, $month, $day) = explode('-', $value);
                $viewValue = compact('year', 'month', 'day');
                $field .= '_' . $betweenDate;
            } elseif ($options['searchType'] == 'multipleselect') {
                $conditionField .= ' IN';
            }

            // fulltext search adds to $filterConditions itself
            if ($options['searchType'] != 'fulltext') {
                $filterConditions[$conditionField] = $value;
            }
            $this->_controller->request->data['Filter'][$model][$field] = $viewValue;
        }
        return $filterConditions;
    }

    /**
     * Splits the search term in value to multiple terms and constructs a OR-style
     * conditions array for each term, for each field to be searched.
     *
     * @param string $conditionField Primary field to search in
     * @param string $value Search Term
     * @param array $options Filter configuration
     * @return array
     */
    protected function _getFulltextSearchConditions($conditionField, $value, array $options)
    {
        $searchTerms = explode(' ', $value);
        $searchTerms = array_map('trim', $searchTerms);

        $searchFields = [$conditionField];
        if (!empty($options['searchFields'])) {
            $searchFields = $options['searchFields'];
        }
        $orConditions = [];
        foreach ($searchTerms as $term) {
            $searchFieldConditions = [];
            foreach ($searchFields as $searchField) {
                $searchFieldConditions["{$searchField} LIKE"] = "%{$term}%";
            }
            $orConditions[] = [
                'OR' => $searchFieldConditions
            ];
        }
        return [
            'AND' => $orConditions
        ];
    }

    /**
     * Make sure options arrays are flattened and consolidated before they are used for value
     * checking. This applies to optgroup-style arrays currently
     *
     * @param array $options Options Config
     * @return array
     */
    protected function _flattenValueOptions(array $options)
    {
        $flatOptions = $options;
        if (is_array(current($options))) {
            $flatOptions = [];
            foreach ($options as $group => $valueGroup) {
                $flatOptions = $flatOptions + $valueGroup;
            }
        }
        return $flatOptions;
    }

    /**
     * Formats and enriches field configs
     *
     * @return array
     */
    public function getFilters()
    {
        if (method_exists($this->_controller, 'getListFilters')) {
            $filters = $this->_controller->getListFilters($this->_controller->request->action);
        } elseif (!empty($this->_controller->listFilters[$this->_controller->request->action])) {
            $filters = $this->_controller->listFilters[$this->_controller->request->action];
        }
        if (empty($filters)) {
            return [];
        }
        foreach ($filters['fields'] as $field => &$fieldConfig) {
            if (isset($fieldConfig['type']) && $fieldConfig['type'] == 'select' && !isset($fieldConfig['searchType'])) {
                $fieldConfig['searchType'] = 'select';
                $fieldConfig['inputOptions']['type'] = 'select';
                unset($fieldConfig['type']);
            }
            // backwards compatibility
            if (isset($fieldConfig['type'])) {
                $fieldConfig['inputOptions']['type'] = $fieldConfig['type'];
                unset($fieldConfig['type']);
            }
            if (isset($fieldConfig['label'])) {
                $fieldConfig['inputOptions']['label'] = $fieldConfig['label'];
                unset($fieldConfig['label']);
            }
            if (isset($fieldConfig['searchType']) && in_array($fieldConfig['searchType'], ['select', 'multipleselect'])) {
                if (!isset($fieldConfig['inputOptions']['type'])) {
                    $fieldConfig['inputOptions']['type'] = 'select';
                }
                if (!isset($fieldConfig['inputOptions']['empty'])) {
                    $fieldConfig['inputOptions']['empty'] = true;
                }
            }
            $fieldConfig = Hash::merge($this->defaultListFilter, $fieldConfig);
        }
        return $filters;
    }

    /**
     * Converts POST filter data in array format to a URL
     *
     * @param array $postData Array with Postdata
     * @return array
     */
    public function getRedirectUrlFromPostData(array $postData)
    {
        $urlParams = [];
        foreach ($postData['Filter'] as $model => $fields) {
            foreach ($fields as $field => $value) {
                if (is_array($value) && isset($value['year'])) {
                    $value = "{$value['year']}-{$value['month']}-{$value['day']}";
                    if ($value == '--') {
                        continue;
                    }
                }
                if ($value !== 0 && $value !== '0' && empty($value)) {
                    continue;
                }
                $urlParams["Filter-{$model}-{$field}"] = $value;
            }
        }
        $passedArgs = $this->_controller->passedArgs;
        if (!empty($passedArgs)) {
            $urlParams = Hash::merge($passedArgs, $urlParams);
        }
        $params = $this->_controller->request->query;
        if (!empty($params)) {
            $cleanParams = [];
            foreach ($params as $key => $value) {
                if (substr($key, 0, 7) != 'Filter-') {
                    $cleanParams[$key] = $value;
                }
            }
            $urlParams = Hash::merge($cleanParams, $urlParams);
        }
        return $urlParams;
    }
}
