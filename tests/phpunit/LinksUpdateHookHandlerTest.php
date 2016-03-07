<?php

namespace PageImages\Tests\Hooks;

use PageImages\Hooks\LinksUpdateHookHandler;
use PageImages;
use ParserOutput;
use PHPUnit_Framework_TestCase;
use RepoGroup;

/**
 * @covers PageImages\Hooks\LinksUpdateHookHandler
 *
 * @group PageImages
 *
 * @license WTFPL 2.0
 * @author Thiemo Mättig
 */
class LinksUpdateHookHandlerTest extends PHPUnit_Framework_TestCase {

	public function tearDown() {
		// remove mock added in testGetMetadata()
		RepoGroup::destroySingleton();
		parent::tearDown();
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

		LinksUpdateHookHandler::onLinksUpdate( $linksUpdate );
		$this->assertTrue( property_exists( $linksUpdate, 'mProperties' ), 'precondition' );
		$this->assertSame( 'A.jpg', $linksUpdate->mProperties[PageImages::PROP_NAME] );
	}

	public function testGetMetadata() {
		$file = $this->getMockBuilder( 'File' )
			->disableOriginalConstructor()
			->getMock();
		// ugly hack to avoid all the unmockable crap in FormatMetadata
		$file->expects( $this->any() )
			->method( 'isDeleted' )
			->will( $this->returnValue( true ) );

		$mockRepoGroup = $this->getMockBuilder( 'RepoGroup' )
			->disableOriginalConstructor()
			->getMock();
		$mockRepoGroup->expects( $this->any() )
			->method( 'findFile' )
			->will( $this->returnValue( $file ) );
		RepoGroup::setSingleton( $mockRepoGroup );

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

		LinksUpdateHookHandler::onLinksUpdate( $linksUpdate );
		$this->assertTrue( true, 'no errors in getMetadata' );
	}
}
