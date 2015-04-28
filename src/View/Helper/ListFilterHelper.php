<?php
namespace ListFilter\View\Helper;

use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Cake\View\Helper;

class ListFilterHelper extends Helper {

	public $helpers = array('Html', 'Form');

	public $filters = array();

	protected $_options = array(
		'formActionParams' => array()
	);

/**
 * Set the current filters
 *
 * @param array &$filters array of allowed filters
 * @return void
 */
	public function setFilters(&$filters) {
		$this->filters = $filters;
	}

/**
 * Render the complete filter widget
 *
 * @param array &$filters Filters ro render
 * @param array $options override options
 * @return string
 */
	public function renderFilterbox(&$filters = null, $options = array()) {
		if ($filters) {
			$this->setFilters($filters);
		}
		if (!empty($options)) {
			$this->_options = Hash::merge($this->_options, $options);
		}
		$filterBox = $this->open();
		$filterBox .= $this->renderAll();
		$filterBox .= $this->close();

        $ret = $this->_View->element('ListFilter.wrapper', compact('filterBox'));

		return $ret;
	}

/**
 * Render all filter widgets
 *
 * @return string
 */
	public function renderAll() {
		$widgets = [];

		foreach ($this->filters as $field => $options) {
			$w = $this->filterWidget($field, $options);
			if ($w) {
				$widgets = array_merge($widgets, $w);
			}
		}
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
 * @return string
 */
	public function filterWidget($field, $options = array()) {
		if (empty($options)) {
			$options = $this->filters['field'];
		}
		if (!$options['showFormField']) {
			continue;
		}
		$ret = [];
		switch($options['searchType']) {
			case 'afterDate':
				$inputOptions = Hash::merge(array(
					'label' => $options['label'],
					'type' => $options['type'],
					'options' => $options['options'],
					'empty' => $options['empty'],
					'type' => 'date'
				), $options['inputOptions']);
				$ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
			break;
			case 'betweenDates':
				$fromOptions = array(
					'label' => $options['label'] . ' ' . __d('list_filter', 'from'),
					'empty' => $options['empty'],
					'type' => 'date'
				);
				$toOptions = array(
					'label' => $options['label'] . ' ' . __d('list_filter', 'to'),
					'empty' => $options['empty'],
					'type' => 'date'
				);
				if (!empty($options['inputOptions']['from'])) {
					$fromOptions = Hash::merge($fromOptions, $options['inputOptions']['from']);
				}
				if (!empty($options['inputOptions']['to'])) {
					$toOptions = Hash::merge($toOptions, $options['inputOptions']['to']);
				}

				$ret[] = $this->Form->input('Filter.' . $field . '_from', $fromOptions);
				$ret[] = $this->Form->input('Filter.' . $field . '_to', $toOptions);

				break;
			case 'multipleselect':
				$inputOptions = Hash::merge(array(
					'label' => $options['label'],
					'type' => 'select',
					'options' => $options['options'],
					'empty' => $options['empty'],
					'multiple' => true,
					'class' => 'select2'
				), $options['inputOptions']);
				$ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
				break;
			default:
				$inputOptions = Hash::merge(array(
					'label' => $options['label'],
					'type' => $options['type'],
					'options' => $options['options'],
					'empty' => $options['empty']
				), $options['inputOptions']);
				$ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
				break;
		}
		return $ret;
	}

/**
 * Opens the listfilter widget
 *
 * @param string $title Fieldset caption
 * @return string
 */
	public function open($title = null) {
		$filterActive = (isset($this->_View->viewVars['filterActive'])
							&& $this->_View->viewVars['filterActive'] === true);
		$classes = '';

		if (!$title) {
			$title = __d('list_filter', 'list_filter.filter_fieldset_title');
		}

		if ($filterActive) {
			$classes .= ' opened';
		} else {
			$classes .= ' closed';
		}
		$ret = '<div class="panel panel-default list-filter ' . $classes . '">';

		// Panel Heading
		$ret .= '<div class="panel-heading">' . $title;
		$ret.= '<div class="pull-right">' . '<a class="btn btn-xs toggle-btn"><i class="fa fa-plus"></i></a>' . '</div>';

		$ret.= '</div>';

		// Panel Body
		$ret .= '<div class="panel-body" style="display: none">';

		$options = Hash::merge(array('url' => $this->here), $this->_options['formActionParams']);
		$ret .= $this->Form->create('Filter', $options);
		return $ret;
	}

/**
 * Closes the listfilter widget
 *
 * @param bool $includeButton add the search button
 * @param bool $includeResetLink add the reset button
 * @return string
 */
	public function close($includeButton = true, $includeResetLink = true) {
		$ret = '<div class="submit-group">';
		if ($includeButton) {
			$ret .= $this->button();
		}
		if ($includeResetLink) {
			$ret .= ' ' . $this->resetLink();
		}
		$ret .= '</div></div>';
		$ret .= $this->Form->end();
		$ret .= '</div>';
		return $ret;
	}

/**
 * Outputs the search button
 *
 * @param string $title button caption
 * @return string
 */
	public function button($title = null) {
		if (!$title) {
			$title = __d('list_filter', 'list_filter.search');
		}
		return $this->Form->button(__d('list_filter', $title), array('div' => false, 'class' => 'btn btn-xs btn-primary'));
	}

/**
 * Outputs the filter reset link
 *
 * @param string $title caption for the reset button
 * @return string
 */
	public function resetLink($title = null) {
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
		$params['controller'] = Inflector::underscore($this->_View->request->controller);
		$params['action'] = $this->request->action;
		return $this->Html->link($title, Router::url($params), array('class' => 'btn btn-default btn-xs'));
	}

/**
 * Adds ListFilter-relevant named params to the given url. Used for detail links
 *
 * @param array $url URL to link to
 * @return array
 */
	public function addListFilterParams(array $url) {
		foreach ($this->_View->request->query as $key => $value) {
			if (substr($key, 0, 7) == 'Filter.' || in_array($key, ['page', 'sort', 'direction'])) {
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
	public function backToListButton($title = null, array $url = null, array $options = []) {
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

        if(!empty($options['useReferer']) && $this->request->referer() != '/') {
            $referer = parse_url($this->request->referer());
            $url = $referer['path'] . '?';
            if(!empty($referer['query'])) {
                $url .= $referer['query'];
            }
        }
        
		if (!$title) {
			$title = '<span class="button-text">' . __('forms.back_to_list') . '</span>';;
		}

		if ($options['additionalClasses']) {
			$options['class'] .= ' ' . $options['additionalClasses'];
		}
        
        if(empty($options['useReferer'])) {
            $url = $this->addListFilterParams($url);
        }
		$button = $this->Html->link('<i class="fa fa-arrow-left"></i> ' . $title, $url, $options);
		return $button;
	}
}