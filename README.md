![CakePHP 3 List Filter Plugin](https://raw.githubusercontent.com/scherersoftware/cake-list-filter/master/cake-list-filter.png)

[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.txt)
[![Build Status](https://travis-ci.org/scherersoftware/cake-list-filter.svg?branch=master)](https://travis-ci.org/scherersoftware/cake-list-filter)

A CakePHP 3 plugin which provides an easy way to create filters for CRUD lists. The plugin will dynamically render the markup necessary and manipulate the controller's `$paginate` config dynamically.

## Installation

    composer require "codekanzlei/cake-list-filter": "dev-master"

In your App's `config/bootstrap.php` load the plugin:

```
Plugin::load('ListFilter', ['bootstrap' => false, 'routes' => false]);
```

In your AppController, or in the controller where you'd like to use ListFilter, load the `ListFilterComponent` and `ListFilterHelper`.

```
public $helpers = [
    'ListFilter.ListFilter'
];

public $components = [
    'ListFilter.ListFilter'
];
```

## Usage

You define filterable fields right in the controller where the pagination happens. This ensures that only the fields you configured can be used for searching, also this automates the generation of the form necessary.

You can either define the fields as the `$listFilter` property in your controller, or implement a getListFilters() callback method.

Multiple listFilters in one controller are possible, as the config is separated by the controller action. In this example we assume you want to have a ListFilter in your controller action `index`.

```
 public $listFilters = [
     'index' => [
         'fields' => [
             'Posts.title' => [
                 'searchType' => 'wildcard',
                 'inputOptions' => [
                     'label' => 'Post Title'
                 ]
             ]
         ]
     ]
 ];
```

Configuring the ListFilters via the callback method provides more flexibility, as it allows to have code executed, which is not possible in a class property.

```
public function getListFilters($action) {
    $filters = [];
    if ($action == 'index') {
        $filters = [
            'fields' => [
                'Posts.title' => [
                    'searchType' => 'wildcard',
                    'inputOptions' => [
                        'label' => __('posts.title')
                    ]
                ],
                'Posts.author_id' => [
                    'searchType' => 'select',
                    'options' => $this->Posts->Authors->find('list'),
                    'inputOptions' => [
                        'label' => __('posts.author')
                    ]
                ]
             ]
        ];
    }
    return $filters;
}
```

We assume that the `index` controller action looks like this:

```
public function index()
{
    $this->paginate['contain'] = ['Authors'];
    $posts = $this->paginate($this->Posts);
    $this->set('posts', $posts);
}
```

Now, in your `index.ctp` view file you can render the filter box like this:

```
<?= $this->ListFilter->renderFilterbox() ?>
```

Your filter box will look like this:

![filterbox](https://cloud.githubusercontent.com/assets/593203/7455325/71dceb4e-f27b-11e4-825a-31b73be2b05e.png)


## Options


### searchType

This can be one of the following:

- `wildcard`: Executes a LIKE search with the given string
- `select`: Renders a dropdown containing the options of the `options` config key. Only keys from this array config can be used to filter, so no URL manipulation is possible.
- `multipleselect`: Analog to select, but renders a dropdown with multiple selection.
- `fulltext`: Will split the given search term by whitespace and makes sure that all the terms are present in the field via LIKE.
    - `searchFields`: If this array is specified, multiple fields will be searched
    - `termsCallback`: A callback function which receives an array containing the search terms and must return an array. Can be used to influence search logic.

### inputOptions

These options will be used to render the form field using `FormHelper::input()`. So in here, you can set the label, add classes to the input, etc.

### searchTermsConjunction

Setting up the component, you may give an additional options array to overwrite the default config. Especially interesting is searchTermsConjunction, which is 'AND' by default
and means, that multiple terms in a fulltext search will be added to the pagination conditions with an AND conjunction between them to narrow down the search results.

If you wish to broaden the results by connecting the terms with OR, you can setup the component like so:
```
public $components = [
    'ListFilter.ListFilter' => [
        'searchTermsConjunction' => 'OR'
    ]
];
```

## Handling many-to-many relations

To handle many-to-many relations, like Users BelongsToMany Groups, you have to build a custom query and pass it to the Paginator, as the Paginator by default can't handle many-to-many relations.

```
public function index()
{
    $this->paginate['contain'] = ['Users'];
    $query = $this->Users->query();
    if (isset($this->paginate['conditions']['Users.group_id'])) {
        $groupId = $this->paginate['conditions']['Users.group_id'];
        unset($this->paginate['conditions']['Users.group_id']);
        $query->matching('Groups', function ($q) use ($groupId) {
            return $q->where(['Group.id' => $groupId]);
        });
    }
    $users = $this->paginate($query);
    $this->set(compact('users'));
}
```

## Customization

If you need a custom layout for your filterbox, you can construct the filter box individually like you please. Every element of the listFilter can be rendered individually:

```
<?= $this->ListFilter->openForm(); ?>
<?= $this->ListFilter->openContainer(); ?>
<div class="row">
    <div class="col-md-4">
        <?= $this->ListFilter->filterWidget('Projects.user_id'); ?>
    </div>
    <div class="col-md-4">
        <?= $this->ListFilter->filterWidget('Objects.customer_id'); ?>
    </div>
    <div class="col-md-4">
        <?= $this->ListFilter->filterWidget('Projects.status'); ?>
    </div>
</div>

<?= $this->ListFilter->filterButton(); ?>
<?= $this->ListFilter->resetButton(); ?>
<?= $this->ListFilter->closeContainer(); ?>
<?= $this->ListFilter->filterWidget('Projects.fulltext_search', [
    'inputOptions' => [
        'label' => false,
        'placeholder' => __('projects.search'),
        'prepend' => '<i class="fa fa-search"></i>'
    ]
]) ?>
<?= $this->ListFilter->closeForm(false, false); ?>
```

Also, you can manipulate default templates and classes used by calling ListFilterHelper::config() with your overrides. These are the options available:

```
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
```
