<?php

namespace Tests;

use PHPUnit_Framework_TestCase;
use ApiPageSet;
use ApiQueryPageImages;
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

class ApiQueryPageImagesTest extends PHPUnit_Framework_TestCase {

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
