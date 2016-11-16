<?php

namespace PageImages\Tests\Hooks;

use LinksUpdate;
use PageImages\Hooks\LinksUpdateHookHandler;
use PageImages;
use ParserOutput;
use PHPUnit_Framework_TestCase;
use RepoGroup;


class LinksUpdateHookHandlerProxy extends PageImages\Hooks\LinksUpdateHookHandler {

	public function getScore( array $image, $position ) {
		return parent::getScore( $image, $position );
	}

	public function scoreFromTable( $value, array $scores ) {
		return parent::scoreFromTable( $value, $scores );
	}

	public function getRatio( array $image ) {
		return parent::getRatio( $image );
	}

	public function getBlacklist() {
		return parent::getBlacklist();
	}
}

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

	/**
	 * @return LinksUpdate
	 */
	private function getLinksUpdate() {
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

		return $linksUpdate;
	}

	/**
	 * @return RepoGroup
	 */
	private function getRepoGroup() {
		$file = $this->getMockBuilder( 'File' )
			->disableOriginalConstructor()
			->getMock();
		// ugly hack to avoid all the unmockable crap in FormatMetadata
		$file->expects( $this->any() )
			->method( 'isDeleted' )
			->will( $this->returnValue( true ) );

		$repoGroup = $this->getMockBuilder( 'RepoGroup' )
			->disableOriginalConstructor()
			->getMock();
		$repoGroup->expects( $this->any() )
			->method( 'findFile' )
			->will( $this->returnValue( $file ) );

		return $repoGroup;
	}

	public function testOnLinksUpdate() {
		$linksUpdate = $this->getLinksUpdate();

		LinksUpdateHookHandler::onLinksUpdate( $linksUpdate );

		$this->assertTrue( property_exists( $linksUpdate, 'mProperties' ), 'precondition' );
		$this->assertSame( 'A.jpg', $linksUpdate->mProperties[PageImages::PROP_NAME] );
	}

	/**
	 * @dataProvider provideGetScore
	 */
	public function testGetScore( $image, $scoreFromTable, $position, $metadata, $expected ) {
		$mock = $this->getMockBuilder( LinksUpdateHookHandlerProxy::class )
			->setMethods( ['scoreFromTable', 'getMetadata', 'getRatio', 'getBlacklist'] )
			->getMock();
        $mock->expects( $this->any() )
            ->method( 'scoreFromTable' )
	        ->will( $this->returnValue( $scoreFromTable ) );
		$mock->expects( $this->any() )
			->method( 'getMetadata' )
			->will( $this->returnValue( $metadata ) );
		$mock->expects( $this->any() )
			->method( 'getRatio' )
			->will( $this->returnValue( 0 ) );
		$mock->expects( $this->any() )
			->method( 'getBlacklist' )
			->will( $this->returnValue( ['blacklisted.jpg' => 1] ) );

		$score = $mock->getScore( $image, $position );
		$this->assertEquals( $expected, $score );
	}

	public function provideGetScore() {
		return [
			[
				['filename' => 'A.jpg', 'handler' => ['width' => 100]],
				100,
				0,
				[],
				// width score + ratio score + position score
				100 + 100 + 8
			],
			[
				['filename' => 'A.jpg', 'fullwidth' => 100],
				50,
				1,
				[],
				// width score + ratio score + position score
				106
			],
			[
				['filename' => 'A.jpg', 'fullwidth' => 100],
				50,
				2,
				[],
				// width score + ratio score + position score
				104
			],
			[
				['filename' => 'A.jpg', 'fullwidth' => 100],
				50,
				3,
				[],
				// width score + ratio score + position score
				103
			],
			[
				['filename' => 'blacklisted.jpg', 'fullwidth' => 100],
				50,
				3,
				[],
				// blacklist score
				-1000
			],
		];
	}

	/**
	 * @dataProvider provideScoreFromTable
	 */
	public function testScoreFromTable( $type, $value, $expected ) {
		global $wgPageImagesScores;

		$proxy = new LinksUpdateHookHandlerProxy;

		$score = $proxy->scoreFromTable( $value, $wgPageImagesScores[$type] );
		$this->assertEquals( $expected, $score );
	}

	public function provideScoreFromTable() {
		return [
			['width', 100, -100],
			['width', 119, -100],
			['width', 300, 10],
			['width', 400, 10],
			['width', 500, 5],
			['width', 600, 5],
			['width', 601, 0],
			['width', 999, 0],
			['galleryImageWidth', 99, -100],
			['galleryImageWidth', 100, 0],
			['galleryImageWidth', 500, 0],
			['ratio', 1, -100],
			['ratio', 3, -100],
			['ratio', 4, 0],
			['ratio', 5, 0],
			['ratio', 10, 5],
			['ratio', 20, 5],
			['ratio', 25, 0],
			['ratio', 30, 0],
			['ratio', 31, -100],
			['ratio', 40, -100],
		];
	}

	public function testFetchingExtendedMetadataFromFile() {
		// Required to make wfFindFile in LinksUpdateHookHandler::getScore return something.
		RepoGroup::setSingleton( $this->getRepoGroup() );
		$linksUpdate = $this->getLinksUpdate();

		LinksUpdateHookHandler::onLinksUpdate( $linksUpdate );

		$this->assertTrue( true, 'no errors in getMetadata' );
	}

}
