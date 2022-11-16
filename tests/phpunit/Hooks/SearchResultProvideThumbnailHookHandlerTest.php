<?php

namespace PageImages\Tests\Hooks;

use LocalFile;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Search\SearchResultThumbnailProvider;
use MediaWikiIntegrationTestCase;
use PageImages\Hooks\SearchResultProvideThumbnailHookHandler;
use PageImages\PageImages;
use PageProps;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use RepoGroup;
use ThumbnailImage;

/**
 * @covers \PageImages\Hooks\SearchResultProvideThumbnailHookHandler
 *
 * @group PageImages
 */
class SearchResultProvideThumbnailHookHandlerTest extends MediaWikiIntegrationTestCase {
	/**
	 * Creates mock object for LocalFile
	 * @param int $size
	 * @param LocalFile $file
	 * @param string $thumbFilePath
	 * @return ThumbnailImage
	 */
	private function getMockThumbnailImage(
		int $size,
		LocalFile $file,
		$thumbFilePath
	): ThumbnailImage {
		$thumbnail = $this->getMockBuilder( ThumbnailImage::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getLocalCopyPath',
				'getWidth',
				'getHeight',
				'getFile',
				'getUrl'
			] )
			->getMock();

			$thumbnail->expects( $this->once() )
				->method( 'getLocalCopyPath' )
				->willReturn( $thumbFilePath );

			$thumbnail->expects( $this->once() )
				->method( 'getUrl' )
				->willReturn( 'https://example.org/test.url' );

			$thumbnail->expects( $this->once() )
				->method( 'getWidth' )
				->willReturn( $size );

			$thumbnail->expects( $this->once() )
				->method( 'getHeight' )
				->willReturn( $size );

			$thumbnail->expects( $this->once() )
				->method( 'getFile' )
				->willReturn( $file );

		return $thumbnail;
	}

	/**
	 * Creates mock object for LocalFile
	 * @param int $size
	 * @param string $thumbFilePath
	 * @param string $filename
	 * @return LocalFile
	 */
	private function getMockLocalFile( int $size, $thumbFilePath, $filename ): LocalFile {
		$file = $this->getMockBuilder( LocalFile::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getName',
				'transform',
				'getMimeType'
			] )
			->getMock();

		$file->expects( $this->once() )
			->method( 'getName' )
			->willReturn( $filename );

		$file->expects( $this->once() )
			->method( 'transform' )
			->with( [ 'width' => $size , 'height' => $size ] )
			->willReturn( $this->getMockThumbnailImage( $size, $file, $thumbFilePath ) );

		$file->expects( $this->once() )
			->method( 'getMimeType' )
			->willReturn( 'image/jpg' );

		return $file;
	}

	public function testProvideThumbnail() {
		$pageProps = $this->getMockBuilder( PageProps::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getProperties' ] )
			->getMock();

		$pageIdentities = [
			1 => new PageIdentityValue( 1, NS_MAIN, 'dbKey1', PageIdentity::LOCAL ),
			2 => new PageIdentityValue( 2, NS_MAIN, 'dbKey2', PageIdentity::LOCAL ),
			3 => new PageIdentityValue( 3, NS_FILE, 'dbKey3', PageIdentity::LOCAL ),
			4 => new PageIdentityValue( 4, NS_FILE, 'dbKey4', PageIdentity::LOCAL )
		];

		$pageProps->expects( $this->once() )
			->method( 'getProperties' )
			->with(
				$this->anything(),
				(array)PageImages::getPropNames( PageImages::LICENSE_FREE )
			)->willReturn( [
				1 => [
					PageImages::getPropName( true ) => 'File1_free.jpg'
				],
				2 => [
					PageImages::getPropName( true ) => 'File2_free.jpg',
				] ] );

		$repoGroup = $this->getMockBuilder( RepoGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'findFile' ] )
			->getMock();

		$findFileCallback = function ( $filename ) {
			return $this->getMockLocalFile(
				SearchResultThumbnailProvider::THUMBNAIL_SIZE,
				__FILE__,
				$filename
			);
		};
		$repoGroup->expects( $this->exactly( 2 ) )
			->method( 'findFile' )
			->withConsecutive( [ 'File1_free.jpg' ], [ 'File2_free.jpg' ] )
			->willReturnOnConsecutiveCalls(
				new ReturnCallback( $findFileCallback ),
				null
			);

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
		$this->assertSame(
			SearchResultThumbnailProvider::THUMBNAIL_SIZE,
			$results[ 1 ]->getHeight()
		);
		$this->assertGreaterThan( 0, $results[ 1 ]->getSize() );
		$this->assertSame( 'https://example.org/test.url', $results[ 1 ]->getUrl() );
	}
}
