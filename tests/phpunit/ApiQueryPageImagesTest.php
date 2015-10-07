<?php

namespace PageImages\Tests;

use ApiPageSet;
use ApiQueryPageImages;
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
 * @licence GNU GPL v2+
 * @author Thiemo Mättig
 */
class ApiQueryPageImagesTest extends PHPUnit_Framework_TestCase {

	private function getApi() {
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
		$api = $this->getApi();
		$this->assertInstanceOf( 'ApiQueryPageImages', $api );
	}

	public function testGetDescription() {
		$api = $this->getApi();
		$description = $api->getDescription();
		$this->assertInternalType( 'string', $description );
		$this->assertNotEmpty( $description );
	}

	public function testGetCacheMode() {
		$api = $this->getApi();
		$this->assertSame( 'public', $api->getCacheMode( array() ) );
	}

	public function testGetAllowedParams() {
		$api = $this->getApi();
		$params = $api->getAllowedParams();
		$this->assertInternalType( 'array', $params );
		$this->assertNotEmpty( $params );
		$this->assertContainsOnly( 'array', $params );
	}

	public function testGetParamDescription() {
		$api = $this->getApi();
		$descriptions = $api->getParamDescription();
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

}
