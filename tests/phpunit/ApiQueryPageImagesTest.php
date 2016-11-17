<?php

namespace PageImages\Tests;

use ApiPageSet;
use ApiQueryPageImages;
use FakeResultWrapper;
use PageImages;
use PHPUnit_Framework_TestCase;
use Title;

class ApiPageSetStub extends ApiPageSet {

	public function __construct( $goodTitles, $missingTitlesByNamespace ) {
		$this->goodTitles = $goodTitles;
		$this->missingTitlesByNamespace = $missingTitlesByNamespace;
	}

	public function getGoodTitles() {
		return $this->goodTitles;
	}

	public function getMissingTitlesByNamespace() {
		return $this->missingTitlesByNamespace;
	}

}

class ApiQueryPageImagesProxy extends ApiQueryPageImages {

	public function __construct( ApiPageSet $pageSet ) {
		$this->pageSet = $pageSet;
	}

	public function getPageSet() {
		return $this->pageSet;
	}

	public function getTitles() {
		return parent::getTitles();
	}

}

/**
 * @covers ApiQueryPageImages
 *
 * @group PageImages
 *
 * @license WTFPL 2.0
 * @author Sam Smith
 * @author Thiemo Mättig
 */
class ApiQueryPageImagesTest extends PHPUnit_Framework_TestCase {

	private function newInstance() {
		$context = $this->getMockBuilder( 'IContextSource' )
			->disableOriginalConstructor()
			->getMock();

		$main = $this->getMockBuilder( 'ApiMain' )
			->disableOriginalConstructor()
			->getMock();
		$main->expects( $this->once() )
			->method( 'getContext' )
			->will( $this->returnValue( $context ) );

		$query = $this->getMockBuilder( 'ApiQuery' )
			->disableOriginalConstructor()
			->getMock();
		$query->expects( $this->once() )
			->method( 'getMain' )
			->will( $this->returnValue( $main ) );

		return new ApiQueryPageImages( $query, '' );
	}

	public function testConstructor() {
		$instance = $this->newInstance();
		$this->assertInstanceOf( 'ApiQueryPageImages', $instance );
	}

	public function testGetDescription() {
		$instance = $this->newInstance();
		$description = $instance->getDescription();
		$this->assertInternalType( 'string', $description );
		$this->assertNotEmpty( $description );
	}

	public function testGetCacheMode() {
		$instance = $this->newInstance();
		$this->assertSame( 'public', $instance->getCacheMode( array() ) );
	}

	public function testGetAllowedParams() {
		$instance = $this->newInstance();
		$params = $instance->getAllowedParams();
		$this->assertInternalType( 'array', $params );
		$this->assertNotEmpty( $params );
		$this->assertContainsOnly( 'array', $params );
	}

	public function testGetParamDescription() {
		$instance = $this->newInstance();
		$descriptions = $instance->getParamDescription();
		$this->assertInternalType( 'array', $descriptions );
		$this->assertNotEmpty( $descriptions );
	}

	/**
	 * @dataProvider provideGetTitles
	 */
	public function testGetTitles( $titles, $missingTitlesByNamespace, $expected ) {
		$pageSet = new ApiPageSetStub( $titles, $missingTitlesByNamespace );
		$queryPageImages = new ApiQueryPageImagesProxy( $pageSet );

		$this->assertEquals( $expected, $queryPageImages->getTitles() );
	}

	public function provideGetTitles() {
		return array(
			array(
				array( Title::newFromText( 'Foo' ) ),
				array(),
				array( Title::newFromText( 'Foo' ) ),
			),
			array(
				array( Title::newFromText( 'Foo' ) ),
				array(
					NS_TALK => array(
						'Bar' => -1,
					),
				),
				array( Title::newFromText( 'Foo' ) ),
			),
			array(
				array( Title::newFromText( 'Foo' ) ),
				array(
					NS_FILE => array(
						'Bar' => -1,
					),
				),
				array(
					0 => Title::newFromText( 'Foo' ),
					-1 => Title::newFromText( 'Bar', NS_FILE ),
				),
			),
		);
	}

	/**
	 * @dataProvider provideExecute
	 * @param array $requestParams Request parameters to the API
	 * @param array $titles Page titles passed to the API
	 * @param array $queryPageIds Page IDs that will be used for querying the DB.
	 * @param array $queryResults Results of the DB select query
	 * @param int $setResultValueCount The number results the API returned
	 */
	public function testExecute( $requestParams, $titles, $queryPageIds, $queryResults, $setResultValueCount ) {
		$mock = $this->getMockBuilder( ApiQueryPageImages::class )
			->disableOriginalConstructor()
			-> setMethods( ['extractRequestParams', 'getTitles', 'setContinueParameter', 'dieUsage',
				'addTables', 'addFields', 'addWhere', 'select', 'setResultValues'] )
			->getMock();
		$mock->expects( $this->any() )
			->method( 'extractRequestParams' )
			->willReturn( $requestParams );
		$mock->expects( $this->any() )
			->method( 'getTitles' )
			->willReturn( $titles );
		$mock->expects( $this->any() )
			->method( 'select' )
			->willReturn( new FakeResultWrapper( $queryResults ) );

		// continue page ID is not found
		if ( isset( $requestParams['continue'] ) && $requestParams['continue'] > count( $titles ) ) {
				$mock->expects( $this->exactly( 1 ) )
				->method( 'dieUsage' );
		}

		$mock->expects( $this->exactly( count ( $queryPageIds ) > 0 ? 1 : 0 ) )
			->method( 'addWhere' )
			->with( ['pp_page' => $queryPageIds, 'pp_propname' => PageImages::PROP_NAME] );

		$mock->expects( $this->exactly( $setResultValueCount ) )
			->method( 'setResultValues' );

		$mock->execute();
	}

	public function provideExecute() {
		return [
			[
				['prop' => ['thumbnail'], 'thumbsize' => 100, 'limit' => 10],
				[Title::newFromText( 'Page 1' ), Title::newFromText( 'Page 2' )],
				[0, 1],
				[
					(object) ['pp_page' => 0, 'pp_value' => 'A.jpg'],
					(object) ['pp_page' => 1, 'pp_value' => 'B.jpg'],
				],
				2
			],
			[
				['prop' => ['thumbnail'], 'thumbsize' => 200, 'limit' => 10],
				[],
				[],
				[],
				0
			],
			[
				['prop' => ['thumbnail'], 'continue' => 1, 'thumbsize' => 400, 'limit' => 10],
				[Title::newFromText( 'Page 1' ), Title::newFromText( 'Page 2' )],
				[1],
				[
					(object) ['pp_page' => 1, 'pp_value' => 'B.jpg'],
				],
				1
			],
		];
	}
}
