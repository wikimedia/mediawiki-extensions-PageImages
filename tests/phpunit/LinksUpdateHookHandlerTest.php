<?php

namespace PageImages\Tests\Hooks;

use PageImages\Hooks\LinksUpdateHookHandler;
use PageImages;
use ParserOutput;
use PHPUnit_Framework_TestCase;

/**
 * @covers PageImages\Hooks\LinksUpdateHookHandler
 *
 * @group PageImages
 *
 * @license WTFPL 2.0
 * @author Thiemo Mättig
 */
class LinksUpdateHookHandlerTest extends PHPUnit_Framework_TestCase {

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

		$this->assertTrue( LinksUpdateHookHandler::onLinksUpdate( $linksUpdate ) );
		$this->assertTrue( property_exists( $linksUpdate, 'mProperties' ), 'precondition' );
		$this->assertSame( 'A.jpg', $linksUpdate->mProperties[PageImages::PROP_NAME] );
	}

}
