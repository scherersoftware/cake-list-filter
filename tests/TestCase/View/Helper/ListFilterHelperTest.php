<?php
namespace ListFilter\Test\TestCase\View\Helper;

use Cake\TestSuite\TestCase;
use Cake\View\View;
use ListFilter\View\Helper\ListFilterHelper;

/**
 * ListFilter\View\Helper\ListFilterHelper Test Case
 *
 * @property \Cake\View\View                          View
 * @property \ListFilter\View\Helper\ListFilterHelper ListFilter
 */
class ListFilterHelperTest extends TestCase
{

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $this->View = new View();
        $this->ListFilter = new ListFilterHelper($this->View);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown()
    {
        unset($this->ListFilter);
        unset($this->View);
        parent::tearDown();
    }

    /**
     * Test renderFilterbox method
     *
     * @return void
     */
    public function testRenderFilterbox()
    {
        $viewVars = [
            'filterActive' => true,
            'filters' => [
                'Posts.title' => [
                    'searchType' => 'wildcard',
                    'inputOptions' => [
                        'type' => 'text',
                        'label' => 'PostTitle'
                    ]
                ],
            ]
        ];
        $this->View->set($viewVars);
        $html = $this->ListFilter->renderFilterBox();

        $expected = [
            'form' => [
                'method' => 'post', 'action' => '/',
            ]
        ];
        #$this->assertHtml($expected, $html);
    }

    public function testFilterWidgetOptionsMerge()
    {
        $viewVars = [
            'filterActive' => true,
            'filters' => [
                'Posts.title' => [
                    'inputOptions' => [
                        'label' => false
                    ],
                    'searchType' => 'select',
                    'options' => [
                        1 => 'opt1',
                        2 => 'opt2'
                    ]
                ],
            ]
        ];
        $this->View->set($viewVars);

        $result = $this->ListFilter->filterWidget('Posts.title', [
            'options' => [
                1 => 'opt1',
                2 => 'opt2'
            ]
        ]);
        $expected = [
            'div' => [
                'class' => 'input select'
            ],
                'select' => ['name' => 'Filter[Posts][title]', 'id' => 'filter-posts-title'],
                    ['option' => ['value' => 1]],
                    'opt1',
                    '/option',
                    ['option' => ['value' => 2]],
                    'opt2',
                    '/option',
                '/select',
            '/div'
        ];
        $this->assertHtml($expected, $result);
    }
}
