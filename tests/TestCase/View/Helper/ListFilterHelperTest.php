<?php
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
     * Test setFilters method
     *
     * @return void
     */
    public function testSetFilters()
    {
        $this->markTestIncomplete('Not implemented yet.');
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

    /**
     * Test renderAll method
     *
     * @return void
     */
    public function testRenderAll()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test filterWidget method
     *
     * @return void
     */
    public function testFilterWidget()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test open method
     *
     * @return void
     */
    public function testOpen()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test close method
     *
     * @return void
     */
    public function testClose()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test button method
     *
     * @return void
     */
    public function testButton()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test resetLink method
     *
     * @return void
     */
    public function testResetLink()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test addListFilterParams method
     *
     * @return void
     */
    public function testAddListFilterParams()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test backToListButton method
     *
     * @return void
     */
    public function testBackToListButton()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
