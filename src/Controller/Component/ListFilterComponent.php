<?php
namespace ListFilter\Controller\Component;

use Cake\Controller\Component;
use Cake\Event\Event;
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

    public $components = ['Cookie'];

    /**
     * Controller Instance to work with
     *
     * @var \Cake\Controller\Controller
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
        ],
        'FormCookie' => [
            'active' => false,
            'namespace' => 'ListFilter'
        ],
        'Validation' => [
            'validateOptions' => true
        ],
        'searchTermsConjunction' => 'AND'
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
     * @param \Cake\Event\Event $event Controller::startup event
     * @return void
     */
    public function startup(Event $event)
    {
        $this->_controller = $event->getSubject();
        $controllerListFilters = $this->getFilters();

        if (empty($controllerListFilters)) {
            return;
        }
        // Redirect on Form Cookie or Form Session Data
        $this->_handlePersistedFilters();

        // Redirect POST to GET, include FormSession and FormCookie Data
        if ($this->_controller->getRequest()->is('post') && !empty($this->_controller->getRequest()->getData('Filter'))) {
            $redirectUrl = $this->getRedirectUrlFromPostData($this->_controller->getRequest()->getData());
            // Remove page param to paginate from the first page
            unset($redirectUrl['page']);
            // Save ListFilter Form Selection in Session
            if ($this->config['FormSession']['active']) {
                $this->_controller->getRequest()->getSession()->write(
                    $this->_getPersistendStorageKey('FormSession'),
                    $this->_controller->getRequest()->getData('Filter')
                );
            }
            if ($this->config['FormCookie']['active']) {
                $this->Cookie->write(
                    $this->_getPersistendStorageKey('FormCookie'),
                    $this->_controller->getRequest()->getData('Filter')
                );
            }

            return $this->_controller->redirect($redirectUrl);
        }

        // Do the ListFilter Job
        $filterConditions = [];
        if (!empty($this->_controller->getRequest()->getQueryParams())) {
            $filterConditions = $this->_prepareFilterConditions($controllerListFilters);
            $conditions = isset($this->_controller->paginate['conditions']) ? $this->_controller->paginate['conditions'] : [];

            // Handle namespaced ListFilter
            if (isset($this->_controller->paginate[$this->_controller->name])) {
                $conditions = [
                    $this->_controller->name => [
                        'conditions' => Hash::merge($conditions, $filterConditions)
                    ]
                ];
            } else {
                $conditions = [
                    'conditions' => Hash::merge($conditions, $filterConditions)
                ];
            }
            $this->_controller->paginate = Hash::merge($this->_controller->paginate, $conditions);
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
     * Handles filters which were persisted through cookies and / or the session.
     *
     * @return mixed
     */
    protected function _handlePersistedFilters()
    {
        if (!empty($this->_controller->getRequest()->getQuery('resetFilter'))) {
            $this->_controller->getRequest()->getSession()->delete($this->_getPersistendStorageKey('FormSession'));
            $this->Cookie->delete($this->_getPersistendStorageKey('FormCookie'));

            return $this->_controller->redirect($this->_controller->getRequest()->getAttribute('here'));
        }
        $persistedFilterData = [];
        if ($this->config['FormSession']['active']) {
            $persistedFilterData = Hash::merge(
                $persistedFilterData,
                $this->_controller->getRequest()->getSession()->read($this->_getPersistendStorageKey('FormSession'))
            );
        }
        if ($this->config['FormCookie']['active']) {
            $persistedFilterData = Hash::merge(
                $persistedFilterData,
                $this->Cookie->read($this->_getPersistendStorageKey('FormCookie'))
            );
        }
        if (!empty($persistedFilterData)) {
            // Redirect the first time, $persistedFilterData is present (Filterredirect param not set)
            if (empty($this->_controller->getRequest()->getQuery('Filterredirect'))) {
                $request = $this->_controller->getRequest();
                if (!empty($persistedFilterData['Pagination']['page'])) {
                    $request = $request->withParam('pass.page', $persistedFilterData['Pagination']['page']);
                }
                if (!empty($persistedFilterData['Pagination']['sort'])) {
                    $request = $request->withParam('pass.sort', $persistedFilterData['Pagination']['sort']);
                }
                if (!empty($persistedFilterData['Pagination']['direction'])) {
                    $request = $request->withParam('pass.direction', $persistedFilterData['Pagination']['direction']);
                }
                $this->_controller->setRequest($request);
                unset($persistedFilterData['Pagination']);
                $redirectUrl = $this->getRedirectUrlFromPostData(['Filter' => $persistedFilterData]);
                $redirectUrl['Filterredirect'] = 1;
                // Redirect
                return $this->_controller->redirect($redirectUrl);
            }
            // Reset Session, if no Filter is set

            if (!$this->filterUrlParameterStatus()) {
                unset($persistedFilterData);
                $this->_controller->getRequest()->getSession()->delete($this->_getPersistendStorageKey('FormSession'));
                $this->Cookie->delete($this->_getPersistendStorageKey('FormCookie'));
            }

            $persistedFilterData['Pagination'] = [];
            if (!empty($this->_controller->getRequest()->getQuery('page'))) {
                $persistedFilterData['Pagination']['page'] = $this->_controller->getRequest()->getQuery('page');
            }
            if (!empty($this->_controller->getRequest()->getQuery('sort'))) {
                $persistedFilterData['Pagination']['sort'] = $this->_controller->getRequest()->getQuery('sort');
            }
            if (!empty($this->_controller->getRequest()->getQuery('direction'))) {
                $persistedFilterData['Pagination']['direction'] = $this->_controller->getRequest()->getQuery('direction');
            }
            $this->_controller->getRequest()->getSession()->write(
                $this->_getPersistendStorageKey('FormSession'),
                $persistedFilterData
            );
            $this->Cookie->write($this->_getPersistendStorageKey('FormCookie'), $persistedFilterData);
        }
    }

    /**
     * Get persistent storage key for ListFilter form handling
     *
     * @param  string  $key  The storage key defined in config
     * @return string
     */
    protected function _getPersistendStorageKey($key): string
    {
        if (!isset($this->config[$key]['namespace'])) {
            $namespace = 'ListFilter';
        } else {
            $namespace = $this->config[$key]['namespace'];
        }
        $sessionKey = [
            'namespace' => $namespace,
            'plugin' => 'App',
            'controller' => $this->_controller->getRequest()->controller,
            'action' => $this->_controller->getRequest()->action
        ];
        if (!empty($this->_controller->getRequest()->plugin)) {
            $sessionKey['plugin'] = $this->_controller->getRequest()->plugin;
        }

        return implode('.', $sessionKey);
    }

    /**
     * checks URL for any Filter Parameter
     *
     * @return bool
     */
    public function filterUrlParameterStatus(): bool
    {
        foreach ($this->_controller->getRequest()->getQueryParams() as $arg => $value) {
            if (substr($arg, 0, 7) === 'Filter-') {
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
    public function filterActive(): bool
    {
        return !empty($this->_controller->viewBuilder()->getVar('filterActive')) ? $this->_controller->viewBuilder()->getVar('filterActive') : false;
    }

    /**
     * Uses the query parameters to construct the $filters config array to be passed to the front end
     * and sets request data so form fields are pre-filled
     *
     * @param array $controllerListFilters ListFilter configuration
     * @return array
     */
    protected function _prepareFilterConditions(array $controllerListFilters): array
    {
        $filterConditions = [];
        $request = $this->_controller->getRequest();
        foreach ($request->getQueryParams() as $arg => $value) {
            if (substr($arg, 0, 7) !== 'Filter-') {
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

            if ($this->config['Validation']['validateOptions']) {
                // for non-multiselects, check if the value is present in the defined valid options
                if (!empty($options['options'])
                    && $options['searchType'] !== 'multipleselect'
                    && !isset($validOptions[$value])
                ) {
                    continue;
                }
                // for multiselects, filter out values not defined in value options
                if ($options['searchType'] === 'multipleselect') {
                    $value = array_intersect($value, array_keys($validOptions));
                }
            }

            // value to be used for form fields
            $viewValue = $value;

            if ($options['searchType'] === 'wildcard') {
                $value = "%{$value}%";
                $conditionField = $conditionField . ' LIKE';
            } elseif ($options['searchType'] === 'fulltext') {
                $filterConditions = $this->_getFulltextSearchConditions($conditionField, $value, $options);
            } elseif ($options['searchType'] === 'betweenDates') {
                $conditionField = 'DATE(' . $conditionField . ')';
                if ($betweenDate === 'from') {
                    $operator = '>=';
                } elseif ($betweenDate === 'to') {
                    $operator = '<=';
                }
                if (!empty($options['conditionField'])) {
                    $conditionField = $options['conditionField'];
                }
                $conditionField .= ' ' . $operator;
                list($year, $month, $day) = explode('-', $value);
                $viewValue = compact('year', 'month', 'day');
                $field .= '_' . $betweenDate;
            } elseif ($options['searchType'] === 'multipleselect') {
                $conditionField .= ' IN';
            }

            // fulltext search adds to $filterConditions itself
            if ($options['searchType'] !== 'fulltext') {
                $filterConditions[$conditionField] = $value;
            }
            $dataPath = 'Filter.' . $model . '.' . $field;
            $request = $request->withData($dataPath, $viewValue);
        }
        $this->_controller->setRequest($request);

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
    protected function _getFulltextSearchConditions($conditionField, $value, array $options): array
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
        $conditions = [];
        $conjunction = $this->config['searchTermsConjunction'];
        foreach ($searchTerms as $key => $term) {
            $searchFieldConditions = [];

            if (is_array($term)) {
                foreach ($term as $item) {
                    foreach ($searchFields as $searchField) {
                        $searchFieldConditions["{$searchField} LIKE"] = "%{$item}%";
                    }

                    $conditions[$conjunction][$key]['OR'][] = [
                        'OR' => $searchFieldConditions
                    ];
                }
            } else {
                foreach ($searchFields as $searchField) {
                    $searchFieldConditions["{$searchField} LIKE"] = "%{$term}%";
                }

                $conditions[$conjunction][$key] = [
                    'OR' => $searchFieldConditions
                ];
            }
        }

        return $conditions;
    }

    /**
     * Make sure options arrays are flattened and consolidated before they are used for value
     * checking. This applies to optgroup-style arrays currently
     *
     * @param array $options Options Config
     * @return array
     */
    protected function _flattenValueOptions(array $options): array
    {
        $flatOptions = $options;
        if (is_array(current($options))) {
            $flatOptions = [];
            foreach ($options as $valueGroup) {
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
    public function getFilters(): array
    {
        $filters = [];
        if (method_exists($this->_controller, 'getListFilters')) {
            $filters = $this->_controller->getListFilters($this->_controller->getRequest()->getParam('action'));
        } elseif (isset($this->_controller->listFilters) && !empty($this->_controller->listFilters[$this->_controller->getRequest()->getParam('action')])) {
            $filters = $this->_controller->listFilters[$this->_controller->getRequest()->getParam('action')];
        }
        if (empty($filters)) {
            return [];
        }
        foreach ($filters['fields'] as &$fieldConfig) {
            if (isset($fieldConfig['type']) && $fieldConfig['type'] === 'select'
                && !isset($fieldConfig['searchType'])
            ) {
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
            if (isset($fieldConfig['searchType'])
                && in_array($fieldConfig['searchType'], ['select', 'multipleselect'])
            ) {
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
    public function getRedirectUrlFromPostData(array $postData): array
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
        $passedArgs = $this->_controller->getRequest()->getParam('pass');
        if (!empty($passedArgs)) {
            $urlParams = Hash::merge($passedArgs, $urlParams);
        }
        $params = $this->_controller->getRequest()->getQueryParams();
        if (!empty($params)) {
            $cleanParams = [];
            foreach ($params as $key => $value) {
                if (substr($key, 0, 7) !== 'Filter-') {
                    $cleanParams[$key] = $value;
                }
            }
            $urlParams = Hash::merge($cleanParams, $urlParams);
        }

        return $urlParams;
    }

    /**
     * Adds ListFilter-relevant named params to the given url. Used for detail links
     *
     * @param array $url URL to link to
     * @return array
     */
    public function addListFilterParams(array $url): array
    {
        foreach ($this->_controller->getRequest()->getQueryParams() as $key => $value) {
            if (substr($key, 0, 7) === 'Filter-' || in_array($key, ['page', 'sort', 'direction'])) {
                $url[$key] = $value;
            }
        }

        return $url;
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
    public function defaultFilters($filters = []): bool
    {
        if (empty($filters)) {
            return false;
        }
        $request = $this->_controller->getRequest();
        foreach ($filters as $key => $value) {
            $filterName = 'Filter-' . str_replace('.', '-', $key);
            $explodedFilterName = explode('-', $filterName);
            $dataPath = $explodedFilterName[0] . '.' . $explodedFilterName[1] . '.' . $explodedFilterName[2];
            if (count($explodedFilterName) !== 3) {
                return false;
            }
            if (empty($request->getQuery($filterName))) {
                // set request POST data so the inputs will be filled
                $request = $request->withData($dataPath, $value);
                $this->_controller->paginate['conditions'][$key] = $value;
            } elseif ($request->getQuery($filterName) === 'all') {
                $request = $request->withoutData($dataPath);
                unset($this->_controller->paginate['conditions'][$key]);
            }
        }
        $this->_controller->setRequest($request);

        return true;
    }
}
