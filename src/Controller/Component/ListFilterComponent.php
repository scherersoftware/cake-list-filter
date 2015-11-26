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

    public $config = [
        'FormSession' => [
            'active' => false,
            'namespace' => 'ListFilter'
        ]
    ];

    /**
     * Initializes the instance
     *
     * @param array $config Component configuration
     * @return void
     */
    public function initialize(array $config)
    {
        $this->config = Hash::merge($this->config, $config);
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
        // Redirect on Form Session Data
        $this->_handleFormSessionData();

        // Redirect POST to GET, include FormSession Data
        if ($this->_controller->request->is('post') && !empty($this->_controller->request->data['Filter'])) {
            $redirectUrl = $this->getRedirectUrlFromPostData($this->_controller->request->data);
            // Remove page param to paginate from the first page
            unset($redirectUrl['page']);
            // Save ListFilter Form Selection in Session
            if ($this->config['FormSession']['active']) {
                $this->_controller->request->session()->write($this->_getSessionKey(), $this->_controller->request->data['Filter']);
            }
            return $this->_controller->redirect($redirectUrl);
        }

        // Do the ListFilter Job
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
     * FormSessionData Handling if it is activated
     *
     * @return void
     */
    protected function _handleFormSessionData()
    {
        if ($this->config['FormSession']['active']) {
            $formSessionData = $this->_controller->request->session()->read($this->_getSessionKey());
            
            // Redirect the first time, $formSessionData is present (Filterredirect param not set)
            if (!empty($formSessionData) && empty($this->_controller->request->query['Filterredirect'])) {
                if (!empty($formSessionData['Pagination']['page'])) {
                    $this->_controller->passedArgs['page'] = $formSessionData['Pagination']['page'];
                }
                if (!empty($formSessionData['Pagination']['sort'])) {
                    $this->_controller->passedArgs['sort'] = $formSessionData['Pagination']['sort'];
                }
                if (!empty($formSessionData['Pagination']['direction'])) {
                    $this->_controller->passedArgs['direction'] = $formSessionData['Pagination']['direction'];
                }
                unset($formSessionData['Pagination']);
                $redirectUrl = $this->getRedirectUrlFromPostData(['Filter' => $formSessionData]);
                $redirectUrl['Filterredirect'] = 1;
                // Redirect
                return $this->_controller->redirect($redirectUrl);
            }
            // Reset Session, if no Filter is set
            if (!$this->_filterUrlParameterStatus()) {
                unset($formSessionData);
                $this->_controller->request->session()->delete($this->_getSessionKey());
            }

            // Set page, sort and direction if FormSession is active and set, but not redirect
            if (!empty($formSessionData)) {
                $formSessionData['Pagination'] = [];
                if (!empty($this->_controller->request->query['page'])) {
                    $formSessionData['Pagination']['page'] = $this->_controller->request->query['page'];
                }
                if (!empty($this->_controller->request->query['sort'])) {
                    $formSessionData['Pagination']['sort'] = $this->_controller->request->query['sort'];
                }
                if (!empty($this->_controller->request->query['direction'])) {
                    $formSessionData['Pagination']['direction'] = $this->_controller->request->query['direction'];
                }
                $this->_controller->request->session()->write($this->_getSessionKey(), $formSessionData);
            }
        }
    }

    /**
     * get Session Key for ListFilter Form Handling
     *
     * @return array
     */
    protected function _getSessionKey()
    {
        $sessionKey = [
            'namespace' => $this->config['FormSession']['namespace'],
            'plugin' => 'App',
            'controller' => $this->_controller->request->controller,
            'action' => $this->_controller->request->action
        ];
        if (!empty($this->_controller->request->plugin)) {
            $sessionKey['plugin'] = $this->_controller->request->plugin;
        }
        $sessionKey = implode('.', $sessionKey);
        return $sessionKey;
    }
    
    /**
     * checks URL for any Filter Parameter
     *
     * @return bool
     */
    protected function _filterUrlParameterStatus()
    {
        foreach ($this->_controller->request->query as $arg => $value) {
            if (substr($arg, 0, 7) == 'Filter-') {
                return true;
            }
        }
        return false;
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
        $searchTerms = array_map('mb_strtolower', $searchTerms);

        if (isset($options['termsCallback']) && is_callable($options['termsCallback'])) {
            $searchTerms = $options['termsCallback']($searchTerms);
        }

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

    /**
     * Set fields given as keys in FilterList and paginator conditions to given values if not set by URL.
     *
     * To reset a default value (normally the empty option), an option with key 'all' must be selected.
     * To imitate the behaviour of empty, set empty in inputOptions to false and add ['all' => ''] to options
     *
     * Array syntax of $filters has to be:
     * [
     *   '{$Tables}.{$field}' => {$defaultValue}
     * ]
     *
     * @param  array  $filters array of fields and their default values like described above
     * @return bool   false if anything goes wrong, else true
     */
    public function defaultFilters($filters = [])
    {
        if (empty($filters)) {
            return false;
        }
        foreach ($filters as $key => $value) {
            $filterName = 'Filter-' . str_replace('.', '-', $key);
            $explodedFilterName = explode('-', $filterName);
            if (count($explodedFilterName) !== 3) {
                return false;
            }
            if (empty($this->_controller->request->query[$filterName])) {
                // set request POST data so the inputs will be filled
                $this->_controller->request->data[$explodedFilterName[0]][$explodedFilterName[1]][$explodedFilterName[2]] = $value;
                $this->_controller->paginate['conditions'][$key] = $value;
            } elseif ($this->_controller->request->query[$filterName] == 'all') {
                unset($this->_controller->request->data[$explodedFilterName[0]][$explodedFilterName[1]][$explodedFilterName[2]]);
                unset($this->_controller->paginate['conditions'][$key]);
            }
        }
        return true;
    }
}
