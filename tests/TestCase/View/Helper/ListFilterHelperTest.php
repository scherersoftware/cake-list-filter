<?php
declare(strict_types = 1);
namespace ListFilter\Test\TestCase\View\Helper;

use Cake\TestSuite\TestCase;
use Cake\View\View;
use ListFilter\View\Helper\ListFilterHelper;

/**
 * ListFilter\View\Helper\ListFilterHelper Test Case
 */
class ListFilterHelperTest extends TestCase
{

    /**
     * @var \Cake\View\View
     */
    private $View;

    /**
     * @var \ListFilter\View\Helper\ListFilterHelper
     */
    private $ListFilter;

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp(): void
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
    public function tearDown(): void
    {
        unset($this->ListFilter);
        unset($this->View);
        parent::tearDown();
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
