<?php

class PageImages {
	/**
	 * Returns true if data for this title should be saved
	 *
	 * @param Title $title
	 * @return bool
	 */
	private static function processThisTitle( Title $title ) {
		static $flipped = false;
		if ( $flipped === false ) {
			global $wgPageImagesNamespaces;
			$flipped = array_flip( $wgPageImagesNamespaces );
		}
		return isset( $flipped[$title->getNamespace()] );
	}

	/**
	 * ParserMakeImageParams hook handler, saes extended information about images used on page
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserMakeImageParams
	 * @param Title $title
	 * @param File|bool $file
	 * @param array $params
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserMakeImageParams( Title $title, $file, array &$params, Parser $parser ) {
		if ( !$file || !self::processThisTitle( $parser->getTitle() ) ) {
			return true;
		}
		$out = $parser->getOutput();
		if ( !isset( $out->pageImages ) ) {
			$out->pageImages = array();
		}
		$myParams = $params;
		if ( !isset( $myParams['handler']['width'] ) ) {
			if ( isset( $myParams['frame']['thumbnail'] )
				|| isset( $myParams['frame']['thumb'] )
				|| isset( $myParams['frame']['frameless'] ) )
			{
				$myParams['handler']['width'] = 250;
			} else {
				$myParams['handler']['width'] = $file->getWidth();
			}
		}
		$myParams['filename'] = $title->getDBkey();
		$out->pageImages[] = $myParams;
		return true;
	}

	/**
	 * LinksUpdate hook handler, sets at most 2 page properties depending on images on page
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LinksUpdate
	 * @param LinksUpdate $lu
	 * @return bool
	 */
	public static function onLinksUpdate( LinksUpdate $lu ) {
		if ( !isset( $lu->getParserOutput()->pageImages ) ) {
			return true;
		}
		$images = $lu->getParserOutput()->pageImages;
		$scores = array();
		$counter = 0;
		foreach ( $images as $image ) {
			$fileName = $image['filename'];
			if ( !isset( $scores[$fileName] ) ) {
				$scores[$fileName] = -1;
			}
			$scores[$fileName] = max( $scores[$fileName], self::getScore( $image, $counter++ ) );
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

	/**
	 * OpenSearchXml hook handler, enhances Extension:OpenSearchXml results with this GagaImages data
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LinksUpdate
	 * @param array $results
	 * @return bool
	 */
	public static function onOpenSearchXml( &$results ) {
		global $wgPageImagesExpandOpenSearchXml;
		if ( !$wgPageImagesExpandOpenSearchXml || !count( $results ) ) {
			return true;
		}
		wfProfileIn( __METHOD__ );
		$pageIds = array_keys( $results );
		$api = new ApiMain(
			new FauxRequest( array(
				'action' => 'query',
				'prop' => 'pageimages',
				'piprop' => 'thumbnail',
				'pageids' => implode( '|', $pageIds ),
				'pilimit' => count( $results ),
			) )
		);
		$api->execute();
		$data = $api->getResultData();
		foreach ( $pageIds as $id ) {
			if ( isset( $data['query']['pages'][$id]['thumb'] ) ) {
				$results[$id]['image'] = $data['query']['pages'][$id]['thumb'];
			} else {
				$results[$id]['image'] = null;
			}
		}
		wfProfileOut( __METHOD__ );
		return true;
	}

	/**
	 * Returns score for image, the more the better, if it is less than zero,
	 * the image shouldn't be used for anything
	 * @param array $image: Associative array describing an image
	 * @param int $position: Image order on page
	 * @return int
	 */
	private static function getScore( array $image, $position ) {
		global $wgPageImagesScores;

		$score = 0;
		foreach ( $wgPageImagesScores['width'] as $maxWidth => $scoreDiff ) {
			if ( $image['handler']['width'] <= $maxWidth ) {
				$score += $scoreDiff;
				break;
			}
		}
		if ( isset( $wgPageImagesScores['position'][$position] ) ) {
			$score += $wgPageImagesScores['position'][$position];
		}
		$blacklist = self::getBlacklist();
		if ( isset( $blacklist[$image['filename']] ) ) {
			$score -= 1000;
		}
		return $score;
	}

	/**
	 * Returns a list of images blacklisted from influencing this extension's output
	 * @return array: Flipped associative array in format "image BDB key" => int
	 */
	private static function getBlacklist() {
		global $wgPageImagesBlacklist, $wgPageImagesBlacklistExpiry, $wgMemc;
		static $list = false;
		if ( $list !== false ) {
			return $list;
		}
		wfProfileIn( __METHOD__ );
		$key = wfMemcKey( 'pageimages', 'blacklist' );
		$list = $wgMemc->get( $key );
		if ( $list !== false ) {
			wfProfileOut( __METHOD__ );
			return $list;
		}
		wfDebug( __METHOD__ . "(): cache miss\n" );
		$list = array();
		foreach ( $wgPageImagesBlacklist as $source ) {
			switch ( $source['type'] ) {
				case 'db':
					$list = array_merge( $list, self::getDbBlacklist( $source['db'], $source['page'] ) );
					break;
				case 'url':
					$list = array_merge( $list, self::getUrlBlacklist( $source['url'] ) );
					break;
				default:
					throw new MWException( __METHOD__ . "(): unrecognized image blacklist type '{$source['type']}'" );
			}
		}
		$list = array_flip( $list );
		$wgMemc->set( $key, $list, $wgPageImagesBlacklistExpiry );
		wfProfileOut( __METHOD__ );
		return $list;
	}

	/**
	 * Returns list of images linked by the given blacklist page
	 * @param string|int $dbName: Database name or false for current database
	 * @param string $page
	 * @return array
	 */
	private static function getDbBlacklist( $dbName, $page ) {
		wfProfileIn( __METHOD__ );
		$dbr = wfGetDB( DB_SLAVE, array(), $dbName );
		$title = Title::newFromText( $page );
		$list = array();
		$id = $dbr->selectField( 'page',
			'page_id',
			array( 'page_namespace' => $title->getNamespace(), 'page_title' => $title->getDBkey() ),
			__METHOD__
		);
		if ( $id ) {
			$res = $dbr->select( 'pagelinks',
				'pl_title',
				array( 'pl_from' => $id, 'pl_namespace' => NS_FILE ),
				__METHOD__
			);
			foreach ( $res as $row ) {
				$list[] = $row->pl_title;
			}
		}
		wfProfileOut( __METHOD__ );
		return $list;
	}

	/**
	 * Returns list of images on given remote blacklist page.
	 * Not quite 100% bulletproof due to localised namespaces and so on.
	 * Though if you beat people if they add bad entries to the list... :)
	 * @param string $url
	 * @return array
	 */
	private static function getUrlBlacklist( $url ) {
		global $wgFileExtensions;
		wfProfileIn( __METHOD__ );
		$list = array();
		$text = Http::get( $url, 3 );
		$regex = '/\[\[:([^|\#]*?\.(?:' . implode( '|', $wgFileExtensions ) . '))/i';
		if ( $text && preg_match_all( $regex, $text, $matches ) ) {
			foreach ( $matches[1] as $s ) {
				$t = Title::makeTitleSafe( NS_FILE, $s );
				if ( $t ) {
					$list[] = $t->getDBkey();
				}
			}
		}
		wfProfileOut( __METHOD__ );
		return $list;
	}
}
