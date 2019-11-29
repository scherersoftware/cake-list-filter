<?php
declare(strict_types=1);
namespace ListFilter\Controller\Component;

use Cake\Controller\Component;
use Cake\Event\Event;
use Cake\Http\Cookie\Cookie;
use Cake\Http\Response;
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
     * Default array structure of a list filter.
     *
     * @var array
     */
    public $defaultListFilter = [
        'searchType' => 'wildcard',
        'inputOptions' => [
            'type' => 'text',
        ],
    ];

    /**
     * Default configuration
     *
     * @var array
     */
    protected $_defaultConfig = [
        'FormSession' => [
            'active' => false,
            'namespace' => 'ListFilter',
        ],
        'FormCookie' => [
            'active' => false,
            'namespace' => 'ListFilter',
        ],
        'Validation' => [
            'validateOptions' => true,
        ],
        'searchTermsConjunction' => 'AND',
    ];

    /**
     * Startup callback
     *
     * @param \Cake\Event\Event $event Controller::startup event
     * @return void
     */
    public function startup(Event $event): void
    {
        $controllerListFilters = $this->getFilters();

        if (empty($controllerListFilters)) {
            return;
        }

        // Redirect on Form Cookie or Form Session Data
        if ($response = $this->_handlePersistedFilters()) {
            $event->setResult($response);

            return;
        }

        $request = $this->getController()->getRequest();

        // Redirect POST to GET, include FormSession and FormCookie Data
        if ($request->is('post') && !empty($request->getData('Filter'))) {
            $redirectUrl = $this->getRedirectUrlFromPostData($request->getData());
            // Remove page param to paginate from the first page
            unset($redirectUrl['page']);

            // Save ListFilter Form Selection in Session
            if ($this->getConfig('FormSession.active')) {
                $request->getSession()->write(
                    $this->_getPersistendStorageKey('FormSession'),
                    $request->getData('Filter')
                );

                $event->setResult($this->getController()->getResponse()
                    ->withCookie(new Cookie(
                        $this->_getPersistendStorageKey('FormCookie'),
                        $request->getData('Filter'),
                        null,
                        '/',
                        '',
                        true,
                        true
                    )));

                return;
            }

            $event->setResult($this->getController()->redirect($redirectUrl));

            return;
        }

        // Do the ListFilter Job
        $filterConditions = [];
        if (!empty($request->getQueryParams())) {
            $filterConditions = $this->_prepareFilterConditions($controllerListFilters);
            $conditions = isset($this->getController()->paginate['conditions']) ? $this->getController()->paginate['conditions'] : [];

            // Handle namespaced ListFilter
            if (isset($this->getController()->paginate[$this->getController()->getName()])) {
                $conditions = [
                    $this->getController()->getName() => [
                        'conditions' => Hash::merge($conditions, $filterConditions),
                    ],
                ];
            } else {
                $conditions = [
                    'conditions' => Hash::merge($conditions, $filterConditions),
                ];
            }

            $this->getController()->paginate = Hash::merge($this->getController()->paginate, $conditions);
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
        $this->getController()->set('filters', $controllerListFilters['fields']);
        $this->getController()->set('filterActive', !empty($filterConditions));
    }

    /**
     * Formats and enriches field configs
     *
     * @return array
     */
    public function getFilters(): array
    {
        $filters = [];
        if (method_exists($this->getController(), 'getListFilters')) {
            $filters = $this->getController()->getListFilters($this->getController()->getRequest()->getParam('action'));
        } elseif (isset($this->getController()->listFilters) && !empty($this->getController()->listFilters[$this->getController()->getRequest()->getParam('action')])) {
            $filters = $this->getController()->listFilters[$this->getController()->getRequest()->getParam('action')];
        }
        if (empty($filters)) {
            return [];
        }
        foreach ($filters['fields'] as &$fieldConfig) {
            if (isset($fieldConfig['type']) && $fieldConfig['type'] == 'select'
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
     * Handles filters which were persisted through cookies and / or the session.
     *
     * @return Response|null
     */
    protected function _handlePersistedFilters(): ?Response
    {
        $request = $this->getController()->getRequest();

        if (!empty($request->getQuery('resetFilter'))) {
            $request->getSession()->delete($this->_getPersistendStorageKey('FormSession'));

            return $this->getController()->getResponse()->withExpiredCookie(new Cookie($this->_getPersistendStorageKey('FormCookie')));
        }
        $persistedFilterData = [];
        if ($this->getConfig('FormSession.active')) {
            $persistedFilterData = Hash::merge(
                $persistedFilterData,
                $request->getSession()->read($this->_getPersistendStorageKey('FormSession'))
            );

            $persistedFilterData = Hash::merge(
                $persistedFilterData,
                $request->getCookie($this->_getPersistendStorageKey('FormCookie'))
            );
        }
        if (!empty($persistedFilterData)) {
            // Redirect the first time, $persistedFilterData is present (Filterredirect param not set)
            if (empty($request->getQuery('Filterredirect'))) {
                if (!empty($persistedFilterData['Pagination']['page'])) {
                    $request = $request
                        ->withParam('page', $persistedFilterData['Pagination']['page']);
                }
                if (!empty($persistedFilterData['Pagination']['sort'])) {
                    $request = $request
                        ->withParam('sort', $persistedFilterData['Pagination']['sort']);
                }
                if (!empty($persistedFilterData['Pagination']['direction'])) {
                    $request = $request
                        ->withParam('direction', $persistedFilterData['Pagination']['direction']);
                }
                unset($persistedFilterData['Pagination']);
                $redirectUrl = $this->getRedirectUrlFromPostData(['Filter' => $persistedFilterData]);
                $redirectUrl['Filterredirect'] = 1;

                $this->getController()->setRequest($request);

                // Redirect
                return $this->getController()->redirect($redirectUrl);
            }
            // Reset Session, if no Filter is set

            if (!$this->filterUrlParameterStatus()) {
                unset($persistedFilterData);
                $request->getSession()->delete($this->_getPersistendStorageKey('FormSession'));

                return $this->getController()->getResponse()->withExpiredCookie(new Cookie($this->_getPersistendStorageKey('FormCookie')));
            }

            $persistedFilterData['Pagination'] = [];
            if (!empty($request->getQuery('page'))) {
                $persistedFilterData['Pagination']['page'] = $request->getQuery('page');
            }
            if (!empty($request->getQuery('sort'))) {
                $persistedFilterData['Pagination']['sort'] = $request->getQuery('sort');
            }
            if (!empty($request->getQuery('direction'))) {
                $persistedFilterData['Pagination']['direction'] = $request->getQuery('direction');
            }
            $request->getSession()->write(
                $this->_getPersistendStorageKey('FormSession'),
                $persistedFilterData
            );

            return $this->getController()->getResponse()
                ->withCookie(new Cookie(
                        $this->_getPersistendStorageKey('FormCookie'),
                        $persistedFilterData,
                        null,
                        '/',
                        '',
                        true,
                        true
                    )
                );
        }

        return null;
    }

    /**
     * Get persistent storage key for ListFilter form handling
     *
     * @param string $key The storage key defined in config
     * @return string
     */
    protected function _getPersistendStorageKey(string $key): string
    {
        $request = $this->getController()->getRequest();
        if (!empty($this->getConfig($key . '.namespace'))) {
            $namespace = 'ListFilter';
        } else {
            $namespace = $this->getConfig($key . '.namespace');
        }
        $sessionKey = [
            'namespace' => $namespace,
            'plugin' => 'App',
            'controller' => $request->getParam('controller'),
            'action' => $request->getParam('action'),
        ];
        if (!empty($request->getParam('plugin'))) {
            $sessionKey['plugin'] = $request->getParam('plugin');
        }
        $sessionKey = implode('.', $sessionKey);

        return $sessionKey;
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

        $request = $this->getController()->getRequest();

        $passedArgs = $request->getParam('pass');
        if (!empty($passedArgs)) {
            $urlParams = Hash::merge($passedArgs, $urlParams);
        }
        $params = $request->getQueryParams();
        if (!empty($params)) {
            $cleanParams = [];
            foreach ($params as $key => $value) {
                if (strpos((string)$key, 'Filter-') !== 0) {
                    $cleanParams[$key] = $value;
                }
            }
            $urlParams = Hash::merge($cleanParams, $urlParams);
        }

        return $urlParams;
    }

    /**
     * checks URL for any Filter Parameter
     *
     * @return bool
     */
    public function filterUrlParameterStatus(): bool
    {
        foreach ($this->getController()->getRequest()->getQueryParams() as $arg) {
            if (substr($arg, 0, 7) == 'Filter-') {
                return true;
            }
        }

        return false;
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

        foreach ($this->getController()->getRequest()->getQueryParams() as $arg => $value) {
            if (substr($arg, 0, 7) !== 'Filter-') {
                continue;
            }
            unset($betweenDate);
            [,$model, $field] = explode('-', $arg);
            // if betweenDate
            $betweenDate = '';
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

            if ($this->getConfig('Validation.validateOptions')) {
                // for non-multiselects, check if the value is present in the defined valid options
                if (!empty($options['options'])
                    && $options['searchType'] !== 'multipleselect'
                    && !isset($validOptions[$value])
                ) {
                    continue;
                }
                // for multiselects, filter out values not defined in value options
                if ($options['searchType'] === 'multipleselect' && isset($validOptions)) {
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
                $operator = '>=';
                if ($betweenDate === 'from') {
                    $operator = '>=';
                } elseif ($betweenDate === 'to') {
                    $operator = '<=';
                }
                if (!empty($options['conditionField'])) {
                    $conditionField = $options['conditionField'];
                }
                $conditionField .= ' ' . $operator;
                [$year, $month, $day] = explode('-', $value);
                $viewValue = compact('year', 'month', 'day');
                $field .= '_' . $betweenDate;
            } elseif ($options['searchType'] == 'multipleselect') {
                $conditionField .= ' IN';
            }

            // fulltext search adds to $filterConditions itself
            if ($options['searchType'] !== 'fulltext') {
                $filterConditions[$conditionField] = $value;
            }
            $this->getController()->setRequest($this->getController()->getRequest()->withData(\implode('.', ['Filter', $model, $field]), $viewValue));
        }

        return $filterConditions;
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
     * Splits the search term in value to multiple terms and constructs a OR-style
     * conditions array for each term, for each field to be searched.
     *
     * @param string $conditionField Primary field to search in
     * @param string $value          Search Term
     * @param array  $options        Filter configuration
     * @return array
     */
    protected function _getFulltextSearchConditions(string $conditionField, string $value, array $options): array
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
        $conjunction = $this->getConfig('searchTermsConjunction');
        foreach ($searchTerms as $key => $term) {
            $searchFieldConditions = [];

            if (is_array($term)) {
                foreach ($term as $item) {
                    foreach ($searchFields as $searchField) {
                        $searchFieldConditions["{$searchField} LIKE"] = "%{$item}%";
                    }

                    $conditions[$conjunction][$key]['OR'][] = [
                        'OR' => $searchFieldConditions,
                    ];
                }
            } else {
                foreach ($searchFields as $searchField) {
                    $searchFieldConditions["{$searchField} LIKE"] = "%{$term}%";
                }

                $conditions[$conjunction][$key] = [
                    'OR' => $searchFieldConditions,
                ];
            }
        }

        return $conditions;
    }

    /**
     * Whether the filter has actually manipulated the pagination conditions
     *
     * @return bool
     */
    public function filterActive(): bool
    {
        $vars = $this->getController()->viewBuilder()->getVars();

        return isset($vars['filterActive']) ? $vars['filterActive'] : false;
    }

    /**
     * Adds ListFilter-relevant named params to the given url. Used for detail links
     *
     * @param array $url URL to link to
     * @return array
     */
    public function addListFilterParams(array $url): array
    {
        foreach ($this->getController()->getRequest()->getQueryParams() as $key => $value) {
            if (substr($key, 0, 7) == 'Filter-' || in_array($key, ['page', 'sort', 'direction'])) {
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
     * @param array $filters array of fields and their default values like described above
     * @return bool   false if anything goes wrong, else true
     */
    public function defaultFilters(array $filters = []): bool
    {
        if (empty($filters)) {
            return false;
        }
        foreach ($filters as $key => $value) {
            $filterName = 'Filter-' . str_replace('.', '-', (string)$key);
            $explodedFilterName = explode('-', $filterName);
            if (count($explodedFilterName) !== 3) {
                return false;
            }
            $request = $this->getController()->getRequest();

            if (empty($request->getQuery($filterName))) {
                // set request POST data so the inputs will be filled
                $request->getData($explodedFilterName[0])[$explodedFilterName[1]][$explodedFilterName[2]] = $value;
                $this->getController()->paginate['conditions'][$key] = $value;
            } elseif ($request->getQuery($filterName) == 'all') {
                unset($request->getData($explodedFilterName[0])[$explodedFilterName[1]][$explodedFilterName[2]]);
                unset($this->getController()->paginate['conditions'][$key]);
            }
        }

        return true;
    }
}
