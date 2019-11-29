<?php
declare(strict_types = 1);
namespace ListFilter\View\Helper;

use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\View\Helper;
use Cake\View\StringTemplateTrait;

/**
 * @property \Cake\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\FormHelper $Form
 */
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
            'buttons' => '<div class="submit-group">{{buttons}}</div>',
        ],
        'containerClasses' => 'panel panel-default list-filter',
        'contentClasses' => 'panel-body',
        'toggleButtonClasses' => 'btn btn-xs toggle-btn',
        'title' => 'Filter',
        'filterButtonOptions' => [
            'div' => false,
            'class' => 'btn btn-xs btn-primary',
        ],
        'resetButtonOptions' => [
            'class' => 'btn btn-default btn-xs',
            'pass' => [
                'resetFilters' => true,
            ],
        ],
    ];

    /**
     * Returns the filter config
     *
     * @return array
     */
    public function getFilters(): array
    {
        if ($this->_filters) {
            return $this->_filters;
        } elseif (!empty($this->getView()->get('filters'))) {
            return $this->getView()->get('filters');
        }

        return [];
    }

    /**
     * Render the complete filter widget
     *
     * @param array $filters Filters to render. If none given, it uses a viewVar called "filters"
     * @return string
     */
    public function renderFilterbox(array $filters = null): string
    {
        if ($filters) {
            $this->_filters = $filters;
        }

        $filterBox = $this->openContainer();
        $filterBox .= $this->openForm();
        $filterBox .= $this->renderAll();
        $filterBox .= $this->closeForm();
        $filterBox .= $this->closeContainer();

        return $this->_View->element('ListFilter.wrapper', [
            'filterBox' => $filterBox,
            'options' => $this->getConfig(),
        ]);
    }

    /**
     * Render all filter widgets
     *
     * @return string
     */
    public function renderAll(): string
    {
        $widgets = [];

        foreach ($this->getFilters() as $field => $options) {
            $w = $this->filterWidget($field, $options, false);
            if (\is_array($w)) {
                $widgets = array_merge($widgets, $w);
            }
        }
        // FIXME allow column-layout to be configured with templates.
        $ret = '<div class="row">';
        foreach ($widgets as $i => $widget) {
            $ret .= '<div class="col-md-6">';
            $ret .= $widget;
            $ret .= '</div>';
            if (((int)$i + 1) % 2 === 0) {
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
    public function filterWidget(string $field, array $options = [], bool $returnString = true)
    {
        $filters = $this->getFilters();
        if (empty($options) && !isset($filters[$field])) {
            trigger_error("No config found for field '{{$field}}'", E_USER_WARNING);

            if ($returnString) {
                return '';
            }

            return [];
        }

        // make sure options isn't merged, as it potentially has int keys which will be doubled
        if (isset($options['options'])) {
            unset($filters[$field]['options']);
        }

        $options = Hash::merge($filters[$field], $options);

        $ret = [];
        switch ($options['searchType']) {
            case 'betweenDates':
                $empty = $options['inputOptions']['empty'] ?? true;

                $fromFieldName = 'Filter.' . $field . '_from';
                $toFieldName = 'Filter.' . $field . '_to';

                if (empty($options['inputOptions']['label'])) {
                    $options['inputOptions']['label'] = $field;
                }

                $fromOptions = [
                    'label' => $options['inputOptions']['label'] . ' ' . __d('list_filter', 'from'),
                    'type' => 'date',
                ];
                $toOptions = [
                    'label' => $options['inputOptions']['label'] . ' ' . __d('list_filter', 'to'),
                    'type' => 'date',
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

                $ret[] = $this->Form->control($fromFieldName, $fromOptions);
                $ret[] = $this->Form->control($toFieldName, $toOptions);
                break;
            case 'multipleselect':
                $inputOptions = Hash::merge([
                    'type' => 'select',
                    'options' => $options['options'],
                    'multiple' => true,
                ], $options['inputOptions']);
                $ret[] = $this->Form->control('Filter.' . $field, $inputOptions);
                break;
            default:
                $inputOptions = Hash::merge([
                    'options' => $options['options'] ?? false,
                ], $options['inputOptions']);
                $ret[] = $this->Form->control('Filter.' . $field, $inputOptions);
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
     * @return string
     */
    public function openContainer(): string
    {
        $classes = $this->getConfig('containerClasses');

        if ($this->filterActive()) {
            $classes .= ' opened';
        } else {
            $classes .= ' closed';
        }

        $ret = $this->templater()->format('containerStart', [
            'attrs' => $this->templater()->formatAttributes([
                'class' => $classes,
            ]),
        ]);
        $ret .= $this->header();
        $ret .= $this->templater()->format('contentStart', [
            'attrs' => $this->templater()->formatAttributes([
                'class' => $this->getConfig('contentClasses'),
            ]),
        ]);

        return $ret;
    }

    /**
     * Closes the HTML container
     *
     * @return string
     */
    public function closeContainer(): string
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
    public function openForm(): string
    {
        $options = Hash::merge(['url' => $this->getView()->getRequest()->getParam('action')], $this->getConfig('formOptions'));

        return $this->Form->create(null, $options);
    }

    /**
     * Closes the listfilter form
     *
     * @param bool $includeFilterButton add the search button
     * @param bool $includeResetButton add the reset button
     * @return string
     */
    public function closeForm(bool $includeFilterButton = true, bool $includeResetButton = true): string
    {
        $buttons = '';
        if ($includeFilterButton) {
            $buttons .= $this->filterButton();
        }
        if ($includeResetButton) {
            $buttons .= ' ' . $this->resetButton();
        }
        $ret = $this->templater()->format('buttons', [
            'buttons' => $buttons,
        ]);
        $ret .= $this->Form->end();

        return $ret;
    }

    /**
     * Renders the header containing title and toggleButton
     *
     * @return string HTML
     */
    public function header(): string
    {
        return $this->templater()->format('header', [
            'title' => $this->getConfig('title'),
            'toggleButton' => $this->toggleButton(),
        ]);
    }

    /**
     * Renders the button for toggling the filter box
     *
     * @return string HTML
     */
    public function toggleButton(): string
    {
        return $this->templater()->format('toggleButton', [
            'attrs' => $this->templater()->formatAttributes([
                'class' => $this->getConfig('toggleButtonClasses'),
            ]),
        ]);
    }

    /**
     * Determines if any ListFilter parameters are set
     *
     * @return bool
     */
    public function filterActive(): bool
    {
        return $this->getView()->get('filterActive') === true;
    }

    /**
     * Outputs the search button
     *
     * @param string $title button caption
     * @param array $options Options
     * @return string
     */
    public function filterButton(string $title = null, array $options = []): string
    {
        if (!$title) {
            $title = __d('list_filter', 'list_filter.search');
        }
        $options = Hash::merge($this->getConfig('filterButtonOptions'), $options);

        return $this->Form->button($title, $options);
    }

    /**
     * Outputs the filter reset link
     *
     * @param string $title caption for the reset button
     * @param array $options Options
     * @return string
     */
    public function resetButton(string $title = null, array $options = []): string
    {
        if (!$title) {
            $title = __d('list_filter', 'list_filter.reset');
        }
        $params = $this->getView()->getRequest()->getQueryParams();
        if (!empty($params)) {
            foreach (array_keys($params) as $field) {
                if (strpos((string)$field, 'Filter-') === 0) {
                    unset($params[$field]);
                }
            }
        }
        $options = Hash::merge($this->getConfig('resetButtonOptions'), $options);
        $url = Hash::merge($params, [
            'resetFilter' => true,
        ]);

        $reversed = Router::reverse($url);

        $redirectUrl = $reversed;
        if (!Router::routeExists($reversed)) {
            $redirectUrl = '/';
        }

        return $this->Html->link($title, $redirectUrl, $options);
    }

    /**
     * Adds ListFilter-relevant named params to the given url. Used for detail links
     *
     * @param array $url URL to link to
     * @return array
     */
    public function addListFilterParams(array $url): array
    {
        foreach ($this->getView()->getRequest()->getQueryParams() as $key => $value) {
            if (strpos($key, 'Filter-') === 0 || in_array($key, ['page', 'sort', 'direction'])) {
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
    public function backToListButton(string $title = null, array $url = null, array $options = []): string
    {
        if (empty($url)) {
            $url = [
                'action' => 'index',
            ];
        }
        $options = Hash::merge([
            'class' => 'btn btn-default btn-xs',
            'escape' => false,
            'additionalClasses' => null,
            'useReferer' => false,
        ], $options);

        $request = $this->getView()->getRequest();

        if (!empty($options['useReferer']) && $request->referer(true) !== '/' && $request->referer(true) !== $request->getParam('action')) {
            $url = $request->referer(true);
        } elseif (empty($options['useReferer'])) {
            $url = $this->addListFilterParams($url);
        }

        if (!$title) {
            $title = '<span class="button-text">' . __d('list_filter', 'forms.back_to_list') . '</span>';
        }

        if ($options['additionalClasses']) {
            $options['class'] .= ' ' . $options['additionalClasses'];
        }

        return $this->Html->link('<i class="fa fa-arrow-left"></i> ' . $title, $url, $options);
    }
}
