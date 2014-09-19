<?php

class PageImages {
	/**
	 * Page property used to store the page image information
	 */
	const PROP_NAME = 'page_image';

	/**
	 * Returns page image for a given title
	 *
	 * @param Title $title: Title to get page image for
	 *
	 * @return File|bool
	 */
	public static function getPageImage( Title $title ) {
		wfProfileIn( __METHOD__ );
		$dbr = wfGetDB( DB_SLAVE );
		$name = $dbr->selectField( 'page_props',
			'pp_value',
			array( 'pp_page' => $title->getArticleID(), 'pp_propname' => self::PROP_NAME ),
			__METHOD__
		);
		$file = false;
		if ( $name ) {
			$file = wfFindFile( $name );
		}
		wfProfileOut( __METHOD__ );
		return $file;
	}

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
	 * ParserMakeImageParams hook handler, saves extended information about images used on page
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserMakeImageParams
	 * @param Title $title
	 * @param File|bool $file
	 * @param array $params
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserMakeImageParams( Title $title, $file, array &$params, Parser $parser ) {
		self::processFile( $parser, $file, $params );
		return true;
	}

	/**
	 * AfterParserFetchFileAndTitle hook handler, saves information about gallery images
	 * @param Parser $parser
	 * @param ImageGalleryBase $ig
	 * @return bool
	 */
	public static function onAfterParserFetchFileAndTitle( Parser $parser, ImageGalleryBase $ig ) {
		foreach ( $ig->getImages() as $image ) {
			self::processFile( $parser, $image[0], null );
		}
		return true;
	}

	/**
	 * @param Parser $parser
	 * @param File|Title|null $file
	 * @param array|null $handlerParams
	 */
	private static function processFile( Parser $parser, $file, $handlerParams ) {
		if ( !$file || !self::processThisTitle( $parser->getTitle() ) ) {
			return;
		}
		if ( !$file instanceof File ) {
			$file = wfFindFile( $file );
			if ( !$file ) {
				return;
			}
		}
		$out = $parser->getOutput();
		if ( !isset( $out->pageImages ) ) {
			$out->pageImages = array();
		}
		if ( is_array( $handlerParams ) ) {
			$myParams = $handlerParams;
			self::calcWidth( $myParams, $file );
		} else {
			$myParams = array();
		}

		$myParams['filename'] = $file->getTitle()->getDBkey();
		$myParams['fullwidth'] = $file->getWidth();
		$myParams['fullheight'] = $file->getHeight();
		$out->pageImages[] = $myParams;
	}

	/**
	 * Estimates image size as displayed if not explicitly provided.
	 * We don't follow the core size calculation algorithm precisely because it's not required and editor's
	 * intentions are more important than the precise number.
	 *
	 * @param array $params
	 * @param File $file
	 */
	private static function calcWidth( array &$params, File $file ) {
		global $wgThumbLimits, $wgDefaultUserOptions;

		if ( isset( $params['handler']['width'] ) ) {
			return;
		}
		if ( isset( $params['handler']['height'] ) && $file->getHeight() > 0 ) {
			$params['handler']['width'] =
				$file->getWidth() * ( $params['handler']['height'] / $file->getHeight() );
		} elseif ( isset( $params['frame']['thumbnail'] )
			|| isset( $params['frame']['thumb'] )
			|| isset( $params['frame']['frameless'] ) )
		{
			$params['handler']['width'] = isset( $wgThumbLimits[$wgDefaultUserOptions['thumbsize']] )
				? $wgThumbLimits[$wgDefaultUserOptions['thumbsize']]
				: 250;
		} else {
			$params['handler']['width'] = $file->getWidth();
		}
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
		wfProfileIn( __METHOD__ );
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
			$lu->mProperties[self::PROP_NAME] = $image;
		}
		wfProfileOut( __METHOD__ );

		return true;
	}

	/**
	 * InfoAction hook handler, adds the page image to the info=action page
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/InfoAction
	 * @param IContextSource $context
	 * @param array $pageInfo
	 * @return bool
	 */
	public static function onInfoAction( IContextSource $context, &$pageInfo ) {
		global $wgDefaultUserOptions, $wgThumbLimits;

		wfProfileIn( __METHOD__ );
		$imageFile = self::getPageImage( $context->getTitle() );
		if ( !$imageFile ) {
			// The page has no image
			wfProfileOut( __METHOD__ );
			return true;
		}

		$thumbSetting = $context->getUser()->getOption(
			'thumbsize',
			$wgDefaultUserOptions['thumbsize']
		);
		$thumbSize = $wgThumbLimits[$thumbSetting];

		$thumb = $imageFile->transform( array( 'width' => $thumbSize ) );
		if ( !$thumb ) {
			wfProfileOut( __METHOD__ );
			return true;
		}
		$imageHtml = $thumb->toHtml(
			array(
				'alt' => $imageFile->getTitle()->getText(),
				'desc-link' => true,
			)
		);

		$pageInfo['header-basic'][] = array(
			$context->msg( 'pageimages-info-label' ),
			$imageHtml
		);
		wfProfileOut( __METHOD__ );
		return true;
	}

	/**
	 * OpenSearchXml hook handler, enhances Extension:OpenSearchXml results with this extension's data
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
		$data = self::getImages( $pageIds, 50 );
		foreach ( $pageIds as $id ) {
			if ( isset( $data[$id]['thumbnail'] ) ) {
				$results[$id]['image'] = $data[$id]['thumbnail'];
			} else {
				$results[$id]['image'] = null;
			}
		}
		wfProfileOut( __METHOD__ );
		return true;
	}

	/**
	 * SpecialMobileEditWatchlist::images hook handler, adds images to mobile watchlist A-Z view
	 *
	 * @param IContextSource $context
	 * @param $watchlist
	 * @param $images
	 * @return true
	 */
	public static function onSpecialMobileEditWatchlist_images( IContextSource $context, $watchlist,
		&$images
	) {
		$ids = array();
		foreach ( $watchlist as $ns => $pages ) {
			foreach ( array_keys( $pages ) as $dbKey ) {
				$title = Title::makeTitle( $ns, $dbKey );
				// Getting page ID here is safe because SpecialEditWatchlist::getWatchlistInfo()
				// uses LinkBatch
				$id = $title->getArticleID();
				if ( $id ) {
					$ids[$id] = $dbKey;
				}
			}
		}
		$data = self::getImages( array_keys( $ids ) );
		foreach ( $data as $id => $page ) {
			if ( isset( $page['pageimage'] ) ) {
				$images[ $page['ns'] ][ $ids[$id] ] = $page['pageimage'];
			}
		}
		return true;
	}

	/**
	 * Returns image information for pages with given ids
	 *
	 * @param array $pageIds
	 * @param $size
	 * @return array
	 */
	private static function getImages( array $pageIds, $size = 0 ) {
		$request = array(
			'action' => 'query',
			'prop' => 'pageimages',
			'piprop' => 'name',
			'pageids' => implode( '|', $pageIds ),
			'pilimit' => 'max',
		);
		if ( $size ) {
			$request['piprop'] = 'thumbnail';
			$request['pithumbsize'] = $size;
		}
		$api = new ApiMain( new FauxRequest( $request ) );
		$api->execute();
		$data = $api->getResultData();
		if ( isset( $data['query']['pages'] ) ) {
			return $data['query']['pages'];
		}
		return array();
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

		if ( isset( $image['handler'] ) ) {
			// Standalone image
			$score = self::scoreFromTable( $image['handler']['width'], $wgPageImagesScores['width'] );
		} else {
			// From gallery
			$score = self::scoreFromTable( $image['fullwidth'], $wgPageImagesScores['galleryImageWidth'] );
		}

		if ( isset( $wgPageImagesScores['position'][$position] ) ) {
			$score += $wgPageImagesScores['position'][$position];
		}

		$ratio = intval( self::getRatio( $image ) * 10 );
		$score += self::scoreFromTable( $ratio, $wgPageImagesScores['ratio'] );

		$blacklist = self::getBlacklist();
		if ( isset( $blacklist[$image['filename']] ) ) {
			$score = -1000;
		}
		return $score;
	}

	/**
	 * Returns width/height ratio of an image as displayed or 0 is not available
	 *
	 * @param array $image
	 * @return int
	 */
	private static function getRatio( array $image ) {
		$width = $image['fullwidth'];
		$height = $image['fullheight'];
		if ( !$width || !$height ) {
			return 0;
		}
		return $width / $height;
	}

	/**
	 * Returns score based on table of ranges
	 *
	 * @param int|float $value
	 * @param array $scores
	 * @return int
	 */
	private static function scoreFromTable( $value, array $scores ) {
		$lastScore = 0;
		foreach ( $scores as $boundary => $score ) {
			if ( $value <= $boundary ) {
				return $score;
			}
			$lastScore = $score;
		}
		return $lastScore;
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
