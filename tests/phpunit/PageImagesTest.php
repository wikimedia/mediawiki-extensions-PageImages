<?php

namespace PageImages\Tests;

use MediaWikiTestCase;
use PageImages;
use ParserOutput;
use Title;

/**
 * @covers PageImages
 *
 * @group PageImages
 * @group Database
 *
 * @license WTFPL 2.0
 * @author Thiemo Mättig
 */
class PageImagesTest extends MediaWikiTestCase {

	public function testPagePropertyName() {
		$this->assertSame( 'page_image', PageImages::PROP_NAME );
	}

	public function testConstructor() {
		$pageImages = new PageImages();
		$this->assertInstanceOf( 'PageImages', $pageImages );
	}

	public function testGivenNonExistingPage_getPageImageReturnsFalse() {
		$title = Title::newFromText( wfRandomString() );
		$title->resetArticleID( 0 );

		$this->assertFalse( PageImages::getPageImage( $title ) );
	}

	public function testOnLinksUpdate() {
		$parserOutput = new ParserOutput();
		$parserOutput->setExtensionData( 'pageImages', array(
			array( 'filename' => 'A.jpg', 'fullwidth' => 100, 'fullheight' => 50 ),
		) );

		$linksUpdate = $this->getMockBuilder( 'LinksUpdate' )
			->disableOriginalConstructor()
			->getMock();
		$linksUpdate->expects( $this->any() )
			->method( 'getParserOutput' )
			->will( $this->returnValue( $parserOutput ) );

		$this->assertTrue( PageImages::onLinksUpdate( $linksUpdate ) );
		$this->assertTrue( property_exists( $linksUpdate, 'mProperties' ), 'precondition' );
		$this->assertSame( 'A.jpg', $linksUpdate->mProperties[PageImages::PROP_NAME] );
	}

}
