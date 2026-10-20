<?php

namespace PageImages\Tests\Hooks;

use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageProps;
use MediaWiki\Search\SearchResultThumbnailProvider;
use MediaWikiIntegrationTestCase;
use PageImages\Hooks\SearchResultProvideThumbnailHookHandler;
use PageImages\PageImages;
use PageImages\Tests\PageImagesTestTrait;

/**
 * @covers \PageImages\Hooks\SearchResultProvideThumbnailHookHandler
 *
 * @group PageImages
 */
class SearchResultProvideThumbnailHookHandlerTest extends MediaWikiIntegrationTestCase {
	use PageImagesTestTrait;

	public function testProvideThumbnail() {
		$pageProps = $this->getMockBuilder( PageProps::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getProperties' ] )
			->getMock();

		$pageIdentities = [
			1 => PageIdentityValue::localIdentity( 1, NS_MAIN, 'dbKey1' ),
			2 => PageIdentityValue::localIdentity( 2, NS_MAIN, 'dbKey2' ),
			3 => PageIdentityValue::localIdentity( 3, NS_FILE, 'dbKey3' ),
			4 => PageIdentityValue::localIdentity( 4, NS_FILE, 'dbKey4' )
		];

		$pageProps->expects( $this->once() )
			->method( 'getProperties' )
			->with(
				$this->anything(),
				(array)PageImages::getPropNames( PageImages::LICENSE_FREE )
			)->willReturn( [
				1 => [
					PageImages::PROP_NAME_FREE => 'File1_free.jpg'
				],
				2 => [
					PageImages::PROP_NAME_FREE => 'File2_free.jpg',
				] ] );

		$repoGroup = $this->createMockRepoGroup( [
			'File1_free.jpg' => SearchResultThumbnailProvider::THUMBNAIL_SIZE,
			'File2_free.jpg' => null,
		] );

		$provider = new SearchResultThumbnailProvider( $repoGroup, $this->createHookContainer() );
		$handler = new SearchResultProvideThumbnailHookHandler( $provider, $pageProps, $repoGroup );

		$results = [ 1 => null, 2 => null, 3 => null, 4 => null ];
		$handler->onSearchResultProvideThumbnail( $pageIdentities, $results );

		$this->assertNull( $results[ 2 ] );
		$this->assertNull( $results[ 3 ] );
		$this->assertNull( $results[ 4 ] );

		$this->assertNotNull( $results[ 1 ] );
		$this->assertSame( 'File1_free.jpg', $results[ 1 ]->getName() );
		$this->assertSame(
			SearchResultThumbnailProvider::THUMBNAIL_SIZE,
			$results[ 1 ]->getWidth()
		);
		$this->assertSame( 'https://example.org/test.url', $results[ 1 ]->getUrl() );
	}
}
