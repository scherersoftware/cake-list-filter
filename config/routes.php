<?php
use Cake\Routing\Router;

Router::plugin('ListFilter', function ($routes) {
	$routes->fallbacks();
});
