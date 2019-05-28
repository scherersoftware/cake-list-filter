<?php
namespace ListFilter\Test\TestCase\Controller\Component;

use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Http\ServerRequest as Request;
use Cake\TestSuite\TestCase;
use ListFilter\Controller\Component\ListFilterComponent;

class ListFilterComponentTest extends TestCase
{

    public function testParamsRedirect()
    {
        $request = new Request([
            'url' => 'controller_posts/index',
            'action' => 'index',
            '_method' => 'POST'
        ]);
        $request = $request->withEnv('REQUEST_METHOD', 'POST');
        $request = $request->withParam('action', 'index');
        $request = $request->withData('Filter', [
            'Posts' => [
                'title' => 'foo',
                'body' => 'bar',
                'multi' => [1, 2]
            ]
        ]);

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Posts.title' => [
                        'searchType' => 'wildcard',
                        'options' => [],
                    ],
                    'Posts.body' => [
                        'searchType' => 'wildcard',
                    ],
                    'Posts.multi' => [
                        'searchType' => 'multipleselect',
                        'options' => [
                            1 => 'one',
                            2 => 'two'
                        ]
                    ]
                ]
            ]
        ];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);
        $this->assertEquals(array_keys($controller->listFilters['index']['fields']), array_keys($ListFilter->getFilters()['fields']));

        // Check if the request is being redirected properly
        $redirectUrl = parse_url($controller->response->getHeader('Location'));
        $this->assertEquals(urldecode($redirectUrl['query']), 'Filter-Posts-title=foo&Filter-Posts-body=bar&Filter-Posts-multi[0]=1&Filter-Posts-multi[1]=2');
    }

    public function testBasicFiltering()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Posts-title=foo&Filter-Posts-body=bar',
            'action' => 'index',
            '_method' => 'POST'
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Posts.title' => [
                        'searchType' => 'wildcard'
                    ],
                    'Posts.body' => [
                        'searchType' => 'wildcard'
                    ],
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        $this->assertEquals([
            'conditions' => [
                'Posts.title LIKE' => '%foo%',
                'Posts.body LIKE' => '%bar%',
            ]
        ], $controller->paginate);
        $this->assertTrue($controller->viewVars['filterActive']);
        $this->assertTrue(isset($controller->viewVars['filters']['Posts.title']));
        $this->assertTrue(isset($controller->viewVars['filters']['Posts.body']));
    }

    public function testSearchTypes()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Comments-comment=foo&Filter-Comments-author_id=1&Filter-Comments-created_from=2015-01-01&Filter-Comments-created_to=2015-01-31&Filter-Posts-multi[0]=1&Filter-Posts-multi[1]=2&Filter-Comments-post_id_optgroup=3',
            'action' => 'index',
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Comments.comment' => [
                        'searchType' => 'wildcard'
                    ],
                    'Comments.author_id' => [
                        'searchType' => 'select',
                        'options' => [
                            1 => 'John Doe',
                            2 => 'Max Example'
                        ]
                    ],
                    'Comments.created' => [
                        'searchType' => 'betweenDates'
                    ],
                    'Posts.multi' => [
                        'searchType' => 'multipleselect',
                        'type' => 'multipleselect',
                        'options' => [
                            1 => 'one',
                            2 => 'two'
                        ]
                    ],
                    'Comments.post_id_optgroup' => [
                        'searchType' => 'select',
                        'options' => [
                            'group1' => [
                                1 => 'one',
                                2 => 'two'
                            ],
                            'group2' => [
                                3 => 'three',
                                4 => 'four'
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        $this->assertEquals([
            'Comments.comment LIKE' => '%foo%',
            'Comments.author_id' => '1',
            'DATE(Comments.created) >=' => '2015-01-01',
            'DATE(Comments.created) <=' => '2015-01-31',
            'Posts.multi IN' => ['1', '2'],
            'Comments.post_id_optgroup' => '3'
        ], $controller->paginate['conditions']);

        // Make sure the request data was modified so that the form fields can be pre-filled
        $this->assertEquals('foo', $controller->getRequest()->getData('Filter.Comments.comment'));
        $this->assertEquals('1', $controller->getRequest()->getData('Filter.Comments.author_id'));
        $this->assertEquals(['year' => '2015', 'month' => '01', 'day' => '01'], $controller->getRequest()->getData('Filter.Comments.created_from'));
        $this->assertEquals(['year' => '2015', 'month' => '01', 'day' => '31'], $controller->getRequest()->getData('Filter.Comments.created_to'));
        $this->assertEquals('3', $controller->getRequest()->getData('Filter.Comments.post_id_optgroup'));
        $this->assertEquals(['1', '2'], $controller->getRequest()->getData('Filter.Posts.multi'));
    }

    public function testFulltextSearchSingleField()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Comments-comment=term1+term2',
            'action' => 'index',
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Comments.comment' => [
                        'searchType' => 'fulltext'
                    ],
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        $this->assertTrue(!empty($controller->paginate['conditions']));
        $this->assertEquals([
            'AND' => [
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term1%',
                    ],
                ],
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term2%',
                    ]
                ]
            ]
        ], $controller->paginate['conditions']);

        $this->assertEquals('term1 term2', $controller->getRequest()->getData('Filter.Comments.comment'));
    }

    /**
     * Test for manipulating the terms to search for via the termCallback option.
     *
     * @return void
     */
    public function testFulltextSearchWithTermsCallback()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Comments-comment=term1+term2',
            'action' => 'index',
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Comments.comment' => [
                        'searchType' => 'fulltext',
                        'termsCallback' => function (array $terms) {
                            $terms[] = 'term3';

                            return $terms;
                        }
                    ],
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        $this->assertTrue(!empty($controller->paginate['conditions']));
        $this->assertEquals([
            'AND' => [
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term1%',
                    ],
                ],
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term2%',
                    ]
                ],
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term3%',
                    ]
                ]
            ]
        ], $controller->paginate['conditions']);

        $this->assertEquals('term1 term2', $controller->getRequest()->getData('Filter.Comments.comment'));
    }

    public function testFulltextSearchMultipleFields()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Comments-comment=term1+term2',
            'action' => 'index',
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Comments.comment' => [
                        'searchType' => 'fulltext',
                        'searchFields' => ['Comments.comment', 'Comments.note']
                    ],
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        $this->assertTrue(!empty($controller->paginate['conditions']));
        $this->assertEquals([
            'AND' => [
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term1%',
                        'Comments.note LIKE' => '%term1%',
                    ],
                ],
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term2%',
                        'Comments.note LIKE' => '%term2%',
                    ]
                ]
            ]
        ], $controller->paginate['conditions']);

        $this->assertEquals('term1 term2', $controller->getRequest()->getData('Filter.Comments.comment'));
    }

    public function testFulltextSearchMultipleFieldsWithOrConjunction()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Comments-comment=term1+term2',
            'action' => 'index',
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Comments.comment' => [
                        'searchType' => 'fulltext',
                        'searchFields' => ['Comments.comment', 'Comments.note']
                    ],
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), ['searchTermsConjunction' => 'OR']);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        $this->assertTrue(!empty($controller->paginate['conditions']));
        $this->assertEquals([
            'OR' => [
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term1%',
                        'Comments.note LIKE' => '%term1%',
                    ],
                ],
                [
                    'OR' => [
                        'Comments.comment LIKE' => '%term2%',
                        'Comments.note LIKE' => '%term2%',
                    ]
                ]
            ]
        ], $controller->paginate['conditions']);

        $this->assertEquals('term1 term2', $controller->getRequest()->getData('Filter.Comments.comment'));
    }

    public function testManipulationHandling()
    {
        $request = new Request([
            'url' => 'controller_posts/index?Filter-Posts-title=foo&Filter-Posts-body=bar&Filter-Posts-author_id=invalid&Filter-Posts-multi[0]=valid1&Filter-Posts-multi[1]=invalid',
            'action' => 'index',
            '_method' => 'POST'
        ]);
        $request = $request->withParam('action', 'index');

        $controller = new Controller($request);
        $controller->listFilters = [
            'index' => [
                'fields' => [
                    'Posts.title' => [
                        'searchType' => 'wildcard'
                    ],
                    'Posts.author_id' => [
                        'searchType' => 'select',
                        'options' => [
                            'valid' => 'valid'
                        ]
                    ],
                    'Posts.multi' => [
                        'searchType' => 'multipleselect',
                        'options' => [
                            'valid1' => 'valid1',
                            'valid2' => 'valid2',
                        ]
                    ]
                ]
            ]
        ];
        $controller->paginate = [];
        $ListFilter = new ListFilterComponent($controller->components(), []);
        $event = new Event('Controller.startup', $controller);
        $ListFilter->startup($event);

        // the 'body' field from the filter URL is not configured in listFilters and
        // should not be added to the paginate array

        // author_id has a value not defined in the options key of the listFilter config
        // and must be ignored
        $this->assertEquals([
            'conditions' => [
                'Posts.title LIKE' => '%foo%',
                // the value 'invalid' was not defined in options, so it must be ignored from the URL
                'Posts.multi IN' => ['valid1']
            ]
        ], $controller->paginate);
    }
}
