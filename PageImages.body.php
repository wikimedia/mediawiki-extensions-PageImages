<?php

class PageImages {
	public static function registerImage( Title $title, File $file, array &$params, Parser $parser ) {
		$out = $parser->getOutput();
		if ( !isset( $out->pageImages ) ) {
			$out->pageImages = array();
		}
		$myParams = $params;
		if ( !isset( $myParams['handler']['width'] ) ) {
			if ( !isset( $myParams['thumbnail'] ) ) {
				$myParams['handler']['width'] = $file->getWidth();
			} else {
				$myParams['handler']['width'] = 250;
			}
		}
		$myParams['file'] = $title->getDBkey();
		$out->pageImages[] = $myParams;
		return true;
	}

	public static function getProperties( LinksUpdate $lu ) {
		if ( !isset( $lu->getParserOutput()->pageImages ) ) {
			return true;
		}
		$images = $lu->getParserOutput()->pageImages;
		$scores = array();
		$imagesByExtension = array( 'jpg' => array(), 'jpeg' => array() );
		$counter = 0;
		foreach ( $images as $image ) {
			$fileName = $image['file'];
			$extension = strtolower( substr( $fileName, strrpos( $fileName, '.' ) + 1 ) );
			$image['extension'] = $extension;
			if ( !isset( $imagesByExtension[$extension][$fileName] ) ) {
				$imagesByExtension[$extension][$fileName] = true;
			}
			if ( !isset( $scores[$fileName] ) ) {
				$scores[$fileName] = -1;
			}
			$scores[$fileName] = max( $scores[$fileName], self::getScore( $image, $counter++ ) );
		}
		$jpegs = array_merge( array_keys( $imagesByExtension['jpg'] ),
			array_keys( $imagesByExtension['jpeg'] )
		);
		$jpegScores = array_map( function( $name ) use ( $scores ) {
				return $scores[$name];
			},
			$jpegs
		);
		arsort( $jpegScores );
		if ( count( $jpegScores ) && $jpegScores[0] >= 0 ) {
			$lu->mProperties['has_photos'] = 1;
		}
		$image = false;
		foreach ( $scores as $name => $score ) {
			if ( $score > 0 && ( !$image || $score > $scores[$image] ) ) {
				$image = $name;
			}
		}
		if ( $image ) {
			$lu->mProperties['page_image'] = $image;
		}
		
		return true;
	}

	private static function getScore( array $image, $position ) {
		global $wgPageImagesScores;

		$score = 0;
		if ( isset( $wgPageImagesScores['extension'][$image['extension']] ) ) {
			$score += $wgPageImagesScores['extension'][$image['extension']];
		}
		foreach ( $wgPageImagesScores['width'] as $maxWidth => $scoreDiff ) {
			if ( $image['handler']['width'] <= $maxWidth ) {
				$score += $scoreDiff;
				break;
			}
		}
		if ( isset( $wgPageImagesScores['position'][$position] ) ) {
			$score += $wgPageImagesScores['position'][$position];
		}
		return $score;
	}
}
