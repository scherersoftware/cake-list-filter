<?php
class ListFilterHelper extends AppHelper {
	public $helpers = array('Html', 'Form', 'Utils');
	private $filters = array();
	
	protected $_options = array(
		'formActionParams' => array()
	);

	public function setFilters(&$filters) {
		$this->filters = $filters;
	}
	
	public function renderFilterbox(&$filters = null, $options = array()) {
		if($filters) {
			$this->setFilters($filters);
		}
		if(!empty($options)) {
			$this->_options = Set::merge($this->_options, $options);
		}
		$ret = $this->open();
		$ret.= $this->renderAll();
		$ret.= $this->close();
		return $ret;
	}
	
	public function renderAll() {
		$widgets = [];
		
		foreach($this->filters as $field => $options) {
			$w = $this->filterWidget($field, $options);
			if($w) {
				$widgets = array_merge($widgets, $w);
			}
		}
		$ret = '<div class="row">';
		foreach($widgets as $i => $widget) {
			$ret.= '<div class="col-md-6">';
			$ret.= $widget;
			$ret.= '</div>';
			if(($i+1) % 2 === 0) {
				$ret.= '</div><div class="row">';
			}
		}
		$ret.= '</div>';
		return $ret;
	}
	
	public function filterWidget($field, $options = array()) {
		if(empty($options)) {
			$options = $this->filters['field'];
		}
		if(!$options['showFormField']) continue;
		$ret = [];
		switch($options['searchType']) {
			case 'afterDate':
			$inputOptions = Set::merge(array(
				'label' => __($options['label']),
				'type' => $options['type'],
				'options' => $options['options'],
				'empty' => $options['empty'],
				'type' => 'date'
			), $options['inputOptions']);
			$ret[] = $this->Form->input('Filter.' . $field, $inputOptions);;
			break;
			case 'betweenDates':
				$fromOptions = array(
					'label' => __($options['label']) . ' ' . __('from'),
					'empty' => $options['empty'],
					'type' => 'date'
				);
				$toOptions = array(
					'label' => __($options['label']) . ' ' . __('to'),
					'empty' => $options['empty'],
					'type' => 'date'
				);
				if(!empty($options['inputOptions']['from'])) {
					$fromOptions = Set::merge($fromOptions, $options['inputOptions']['from']);
				}
				if(!empty($options['inputOptions']['to'])) {
					$toOptions = Set::merge($toOptions, $options['inputOptions']['to']);
				}

				$ret[] =  $this->Form->input('Filter.' . $field . '_from', $fromOptions);
				$ret[] =  $this->Form->input('Filter.' . $field . '_to', $toOptions);

				break;
			case 'multipleselect':
				$inputOptions = Set::merge(array(
					'label' => __($options['label']),
					'type' => 'select',
					'options' => $options['options'],
					'empty' => $options['empty'],
					'multiple' => true,
					'class' => 'select2'
				), $options['inputOptions']);
				$ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
				break;
			default:
				$inputOptions = Set::merge(array(
					'label' => __($options['label']),
					'type' => $options['type'],
					'options' => $options['options'],
					'empty' => $options['empty']
				), $options['inputOptions']);
				$ret[] = $this->Form->input('Filter.' . $field, $inputOptions);
				break;
		}
		return $ret;
	}
	
	public function open($title = null) {
		$filterActive = (isset($this->_View->viewVars['filterActive']) 
							&& $this->_View->viewVars['filterActive'] === true);
		$classes = 'list-filter clearfix well ';

		if($title == null) {
			$title = __('filter_fieldset_title');
		}

		if($filterActive) {
			$classes.= ' opened';
		} else {
			$classes.= ' closed';
		}
		$ret = "<div class='{$classes}'>";
		
		$ret.= "<div class='pull-left'><h3>{$title}</h3></div>";
		$ret.= "<div class='pull-right'>";
			$ret.= $this->Html->link($filterActive ? __('list_filter.close') : __('list_filter.open'), 'javascript:', array('class' => 'btn btn-xs btn-primary toggle'));
		$ret.= "</div>";
		$ret.= "<hr style='clear:both'><div class='content'>";
		
		$options = Set::merge(array('url' => $this->here), $this->_options['formActionParams']);
		$ret.= $this->Form->create('Filter', $options);
		return $ret;
	}	

	public function close($includeButton = true, $includeResetLink = true) {
        $ret = '<div class="submit-group">';
        $ret.= '<span></span>';
		if($includeButton) {
			$ret.= $this->button();
		}
		if($includeResetLink) {
			$ret.= ' ' . $this->resetLink();
		}
		$ret.= '</div></div>';
		$ret.= $this->Form->end();
		$ret.= '</div>';
		return $ret;
	}

	public function button($title = null) {
		if(!$title) {
			$title = __('list_filter.search');
		}
		return $this->Form->submit(__($title), array('div' => false, 'class' => 'btn btn-mini btn-primary'));
	}
	
	public function resetLink($title = null) {
		if(!$title) {
			$title = __('list_filter.reset');
		}
		$params = $this->params['named'];
		if(!empty($params)) {
			foreach($params as $field => $value) {
				if(substr($field, 0, 7) == 'Filter.') {
					unset($params[$field]);
				}
			}
		}
		$params['controller'] = Inflector::underscore($this->params->controller);
		$params['action'] = $this->params->action;
		return $this->Html->link($title, Router::url($params), array('class' => 'btn btn-default btn-mini'));
	}
	
/**
 * Adds ListFilter-relevant named params to the given url. Used for detail links
 *
 * @param array $url 
 * @return array
 */	
	public function addListFilterParams(array $url) {
		foreach($this->_View->params['named'] as $key => $value) {
			if(substr($key, 0, 7) == 'Filter.' || in_array($key, ['page', 'sort', 'direction'])) {
				$url[ $key ] = $value;
			}
		}
		return $url;
	}
	
/**
 * Renders a back-to-list button using the ListFilter-relevant named params
 *
 * @param string $title 
 * @param array $url 
 * @return void
 */	
	public function backToListButton($title = null, array $url = [], array $config = []) {
		if(empty($url)) {
			$url = [
				'action' => 'index'
			];
		}
		$options = array('class'=>'btn btn-default btn-sm');
		if(!empty($config)) {
			$options = Set::merge($options, $config);
		}
		$url = $this->addListFilterParams($url);
		$button = $this->Utils->backButton($title, $url, $options);
		return $button;
	}
}