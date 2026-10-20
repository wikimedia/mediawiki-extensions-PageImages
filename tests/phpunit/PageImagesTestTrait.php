<?php

namespace PageImages\Tests;

use LogicException;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Media\ThumbnailImage;

trait PageImagesTestTrait {

	/**
	 * @param array<string,null|int> $files Filename to null or width
	 */
	private function createMockRepoGroup( array $files ): RepoGroup {
		$repoGroup = $this->getMockBuilder( RepoGroup::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'findFile' ] )
			->getMock();

		$repoGroup->method( 'findFile' )
			->willReturnCallback( function ( $title ) use ( $files ) {
				if ( !array_key_exists( $title, $files ) ) {
					throw new LogicException( "Unexpected title $title" );
				}
				$width = $files[$title];
				if ( $width === null ) {
					return null;
				}

				$file = $this->getMockBuilder( LocalFile::class )
					->disableOriginalConstructor()
					->onlyMethods( [
						'getName',
						'getWidth',
						'getHeight',
						'getMimeType',
						'transform',
					] )
					->getMock();
				$file->method( 'getName' )->willReturn( $title );
				$file->method( 'getWidth' )->willReturn( $width );
				$file->method( 'getHeight' )->willReturn( $width );
				$file->method( 'getMimeType' )->willReturn( 'image/jpg' );

				$thumb = $this->getMockBuilder( ThumbnailImage::class )
					->disableOriginalConstructor()
					->onlyMethods( [
						'getWidth',
						'getHeight',
						'getFile',
						'getUrl',
					] )
					->getMock();
				$thumb->method( 'getUrl' )->willReturn( 'https://example.org/test.url' );
				$thumb->method( 'getWidth' )->willReturn( $width );
				$thumb->method( 'getHeight' )->willReturn( $width );
				$thumb->method( 'getFile' )->willReturn( $file );
				$file->method( 'transform' )->willReturn( $thumb );
				return $file;
			} );

		return $repoGroup;
	}
}
