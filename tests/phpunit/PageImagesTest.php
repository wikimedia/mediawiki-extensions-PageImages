<?php

namespace PageImages\Tests;

use IContextSource;
use MediaWikiTestCase;
use OutputPage;
use PageImages;
use SkinTemplate;
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

	public function testPagePropertyNames() {
		$this->assertSame( 'page_image', PageImages::PROP_NAME );
		$this->assertSame( 'page_image_free', PageImages::PROP_NAME_FREE );
	}

	public function testConstructor() {
		$pageImages = new PageImages();
		$this->assertInstanceOf( 'PageImages', $pageImages );
	}

	public function testGivenNonExistingPage_getPageImageReturnsFalse() {
		$title = $this->newTitle();
		$this->assertFalse( PageImages::getPageImage( $title ) );
	}

	public function testGetPropName() {
		$this->assertSame( 'page_image', PageImages::getPropName( false ) );
		$this->assertSame( 'page_image_free', PageImages::getPropName( true ) );
	}

	public function testGivenNonExistingPage_onBeforePageDisplayDoesNotAddMeta() {
		$context = $this->getMock( IContextSource::class );
		$context->method( 'getTitle' )
			->will( $this->returnValue( $this->newTitle() ) );

		$outputPage = $this->getMock( OutputPage::class, null, [ $context ] );
		$outputPage->expects( $this->never() )
			->method( 'addMeta' );

		PageImages::onBeforePageDisplay( $outputPage, new SkinTemplate() );
	}

	/**
	 * @return Title
	 */
	private function newTitle() {
		$title = Title::newFromText( 'New' );
		$title->resetArticleID( 0 );
		return $title;
	}

}
