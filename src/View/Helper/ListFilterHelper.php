<?php
namespace ListFilter\View\Helper;

use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Cake\View\Helper;
use Cake\View\StringTemplateTrait;

class ListFilterHelper extends Helper
{
    use StringTemplateTrait;

    /**
     * Used Helpers
     *
     * @var array
     */
    public $helpers = ['Html', 'Form'];

    /**
     * ListFilter Config
     *
     * @var array
     */
    protected $_filters = [];

    /**
     * Default Config
     *
     * @var array
     */
    protected $_defaultConfig = [
        'formOptions' => [],
        'includeJavascript' => true,
        'templates' => [
            'containerStart' => '<div{{attrs}}>',
            'containerEnd' => '</div>',
            'toggleButton' => '<a{{attrs}}><i class="fa fa-plus"></i></a>',
            'header' => '<div class="panel-heading">{{title}}<div class="pull-right">{{toggleButton}}</div></div>',
            'contentStart' => '<div{{attrs}}>',
            'contentEnd' => '</div>',
            'buttons' => '<div class="submit-group">{{buttons}}</div>'
        ],
        'containerClasses' => 'panel panel-default list-filter',
        'contentClasses' => 'panel-body',
        'toggleButtonClasses' => 'btn btn-xs toggle-btn',
        'title' => 'Filter',
        'filterButtonOptions' => [
            'div' => false,
            'class' => 'btn btn-xs btn-primary'
        ],
        'resetButtonOptions' => [
            'class' => 'btn btn-default btn-xs'
        ]
    ];

    /**
     * Returns the filter config
     *
     * @return array
     */
    public function getFilters()
    {
        if ($this->_filters) {
            return $this->_filters;
        } elseif (isset($this->_View->viewVars['filters'])) {
            return $this->_View->viewVars['filters'];
        }
        return [];
    }

    /**
     * Render the complete filter widget
     *
     * @param array $filters Filters to render. If none given, it uses a viewVar called "filters"
     * @return string
     */
    public function renderFilterbox($filters = null)
    {
        if ($filters) {
            $this->_filters = $filters;
        }

        $filterBox = $this->openContainer();
        $filterBox .= $this->openForm();
        $filterBox .= $this->renderAll();
        $filterBox .= $this->closeForm();
        $filterBox .= $this->closeContainer();

        $ret = $this->_View->element('ListFilter.wrapper', [
            'filterBox' => $filterBox,
            'options' => $this->config()
        ]);

        return $ret;
    }

    /**
     * Render all filter widgets
     *
     * @return string
     */
    public function renderAll()
    {
        $widgets = [];

        foreach ($this->getFilters() as $field => $options) {
            $w = $this->filterWidget($field, $options, false);
            if ($w) {
                $widgets = array_merge($widgets, $w);
            }
        }
        // FIXME allow column-layout to be configured with templates.
        $ret = '<div class="row">';
        foreach ($widgets as $i => $widget) {
            $ret .= '<div class="col-md-6">';
            $ret .= $widget;
            $ret .= '</div>';
            if (($i + 1) % 2 === 0) {
                $ret .= '</div><div class="row">';
            }
        }
        $ret .= '</div>';
        return $ret;
    }

    /**
     * Outputs a filter widget based on configuration
     *
     * @param string $field The field to filter with
     * @param array $options Options defined in Controller::listFilters()
     * @param bool $returnString If false, an array of the generated widget markup is being returned
     * @return string|array Markup string or array of the markup of one or multiple filter input widgets
     */
    public function filterWidget($field, $options = [], $returnString = true)
    {
        $filters = $this->getFilters();
        if (empty($options) && !isset($filters[$field])) {
            trigger_error("No config found for field '{{$field}}'", E_USER_WARNING);
            return false;
        }

        // make sure options isn't merged, as it potentially has int keys which will be doubled
        if (isset($options['options'])) {
            unset($filters[$field]['options']);
        }

        $options = Hash::merge($filters[$field], $options);

        $ret = [];
        switch ($options['searchType']) {
            case 'betweenDates':
                $empty = isset($options['inputOptions']['empty']) ? $options['inputOptions']['empty'] : true;

                $fromFieldName = 'Filter.' . $field . '_from';
                $toFieldName = 'Filter.' . $field . '_to';

                if (empty($options['inputOptions']['label'])) {
                    $options['inputOptions']['label'] = $field;
                }

                $fromOptions = [
                    'label' => $options['inputOptions']['label'] . ' ' . __d('list_filter', 'from'),
                    'type' => 'date'
                ];
                $toOptions = [
                    'label' => $options['inputOptions']['label'] . ' ' . __d('list_filter', 'to'),
                    'type' => 'date'
                ];
                if (!empty($options['inputOptions']['from'])) {
                    $fromOptions = Hash::merge($fromOptions, $options['inputOptions']['from']);
                }
                if (!empty($options['inputOptions']['to'])) {
                    $toOptions = Hash::merge($toOptions, $options['inputOptions']['to']);
                }

                if ($empty) {
                    // map the empty option to both option arrays
                    $fromOptions['empty'] = true;
                    $toOptions['empty'] = true;
                    // if the empty option was set, make sure date inputs are not set to the current date by default
                    if (empty($fromOptions['val']) && empty($this->Form->context()->val($fromFieldName))) {
                        $fromOptions['val'] = '';
                    }
                    if (empty($toOptions['val']) && empty($this->Form->context()->val($toFieldName))) {
                        $toOptions['val'] = '';
                    }
                }

                $ret[] = $this->Form->input($fromFieldName, $fromOptions);
                $ret[] = $this->Form->input($toFieldName, $toOptions);
                break;
            case 'multipleselect':
                $inputOptions = Hash::merge([
                    'type' => 'select',
                    'options' => $options['options'],
                    'multiple' => true,
                ], $options['inputOptions']);
                $ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
                break;
            default:
                $inputOptions = Hash::merge([
                    'options' => isset($options['options']) ? $options['options'] : false,
                ], $options['inputOptions']);
                $ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
                break;
        }
        if ($returnString) {
            return implode("\n", $ret);
        }
        return $ret;
    }

    /**
     * Opens the HTML container
     *
     * @return HTML
     */
    public function openContainer()
    {
        $classes = $this->config('containerClasses');

        $title = __d('list_filter', 'list_filter.filter_fieldset_title');

        if ($this->filterActive()) {
            $classes .= ' opened';
        } else {
            $classes .= ' closed';
        }

        $ret = $this->templater()->format('containerStart', [
            'attrs' => $this->templater()->formatAttributes([
                'class' => $classes
            ])
        ]);
        $ret .= $this->header();
        $ret .= $this->templater()->format('contentStart', [
            'attrs' => $this->templater()->formatAttributes([
                'class' => $this->config('contentClasses')
            ])
        ]);
        return $ret;
    }

    /**
     * Closes the HTML container
     *
     * @return string
     */
    public function closeContainer()
    {
        $ret = $this->templater()->format('contentEnd', []);
        $ret .= $this->templater()->format('containerEnd', []);
        return $ret;
    }

    /**
     * Opens the listfilter form
     *
     * @return string
     */
    public function openForm()
    {
        $options = Hash::merge(['url' => $this->here], $this->config('formOptions'));
        $ret = $this->Form->create('Filter', $options);
        return $ret;
    }

    /**
     * Closes the listfilter form
     *
     * @param bool $includeFilterButton add the search button
     * @param bool $includeResetButton add the reset button
     * @return string
     */
    public function closeForm($includeFilterButton = true, $includeResetButton = true)
    {
        $buttons = '';
        if ($includeFilterButton) {
            $buttons .= $this->filterButton();
        }
        if ($includeResetButton) {
            $buttons .= ' ' . $this->resetButton();
        }
        $ret = $this->templater()->format('buttons', [
            'buttons' => $buttons
        ]);
        $ret .= $this->Form->end();
        return $ret;
    }

    /**
     * Renders the header containing title and toggleButton
     *
     * @return void
     */
    public function header()
    {
        return $this->templater()->format('header', [
            'title' => $this->config('title'),
            'toggleButton' => $this->toggleButton()
        ]);
    }

    /**
     * Renders the button for toggling the filter box
     *
     * @return string HTML
     */
    public function toggleButton()
    {
        return $this->templater()->format('toggleButton', [
            'attrs' => $this->templater()->formatAttributes([
                'class' => $this->config('toggleButtonClasses')
            ])
        ]);
    }

    /**
     * Determines if any ListFilter parameters are set
     *
     * @return bool
     */
    public function filterActive()
    {
        $filterActive = (isset($this->_View->viewVars['filterActive'])
                        && $this->_View->viewVars['filterActive'] === true);
        return $filterActive;
    }

    /**
     * Outputs the search button
     *
     * @param string $title button caption
     * @return string
     */
    public function filterButton($title = null, array $options = [])
    {
        if (!$title) {
            $title = __d('list_filter', 'list_filter.search');
        }
        $options = Hash::merge($this->config('filterButtonOptions'), $options);
        return $this->Form->button($title, $options);
    }

    /**
     * Outputs the filter reset link
     *
     * @param string $title caption for the reset button
     * @return string
     */
    public function resetButton($title = null, array $options = [])
    {
        if (!$title) {
            $title = __d('list_filter', 'list_filter.reset');
        }
        $params = $this->_View->request->query;
        if (!empty($params)) {
            foreach ($params as $field => $value) {
                if (substr($field, 0, 7) == 'Filter-') {
                    unset($params[$field]);
                }
            }
        }
        $options = Hash::merge($this->config('resetButtonOptions'), $options);
        $params['controller'] = Inflector::underscore($this->_View->request->controller);
        $params['action'] = $this->request->action;
        return $this->Html->link($title, Router::url($params), $options);
    }

    /**
     * Adds ListFilter-relevant named params to the given url. Used for detail links
     *
     * @param array $url URL to link to
     * @return array
     */
    public function addListFilterParams(array $url)
    {
        foreach ($this->_View->request->query as $key => $value) {
            if (substr($key, 0, 7) == 'Filter-' || in_array($key, ['page', 'sort', 'direction'])) {
                $url[$key] = $value;
            }
        }
        return $url;
    }

    /**
     * Renders a back-to-list button using the ListFilter-relevant named params
     *
     * @param string $title button caption
     * @param array $url url to link to
     * @param array $options link() config
     * @return string
     */
    public function backToListButton($title = null, array $url = null, array $options = [])
    {
        if (empty($url)) {
            $url = [
                'action' => 'index'
            ];
        }
        $options = Hash::merge([
            'class' => 'btn btn-default btn-xs',
            'escape' => false,
            'additionalClasses' => null,
            'useReferer' => false
        ], $options);

        if (!empty($options['useReferer']) && $this->request->referer(true) != '/' && $this->request->referer(true) != $this->request->here) {
            $url = $this->request->referer(true);
        }

        if (!$title) {
            $title = '<span class="button-text">' . __d('list_filter', 'forms.back_to_list') . '</span>';
        }

        if ($options['additionalClasses']) {
            $options['class'] .= ' ' . $options['additionalClasses'];
        }

        if (empty($options['useReferer'])) {
            $url = $this->addListFilterParams($url);
        }
        $button = $this->Html->link('<i class="fa fa-arrow-left"></i> ' . $title, $url, $options);
        return $button;
    }
}
