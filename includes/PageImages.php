<?php

/**
 * @license WTFPL 2.0
 * @author Max Semenik
 * @author Brad Jorsch
 * @author Thiemo Mättig
 */
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
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserMakeImageParams
	 *
	 * @param Title $title
	 * @param File|bool $file
	 * @param array &$params
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserMakeImageParams( Title $title, $file, array &$params, Parser $parser ) {
		self::processFile( $parser, $file, $params );
		return true;
	}

	/**
	 * AfterParserFetchFileAndTitle hook handler, saves information about gallery images
	 *
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

		if ( is_array( $handlerParams ) ) {
			$myParams = $handlerParams;
			self::calcWidth( $myParams, $file );
		} else {
			$myParams = array();
		}

		$myParams['filename'] = $file->getTitle()->getDBkey();
		$myParams['fullwidth'] = $file->getWidth();
		$myParams['fullheight'] = $file->getHeight();

		$out = $parser->getOutput();
		$pageImages = $out->getExtensionData( 'pageImages' ) ?: array();
		$pageImages[] = $myParams;
		$out->setExtensionData( 'pageImages', $pageImages );
	}

	/**
	 * Estimates image size as displayed if not explicitly provided.
	 * We don't follow the core size calculation algorithm precisely because it's not required and editor's
	 * intentions are more important than the precise number.
	 *
	 * @param array &$params
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
	 * InfoAction hook handler, adds the page image to the info=action page
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/InfoAction
	 *
	 * @param IContextSource $context
	 * @param array[] &$pageInfo
	 * @return bool
	 */
	public static function onInfoAction( IContextSource $context, &$pageInfo ) {
		global $wgDefaultUserOptions, $wgThumbLimits;

		$imageFile = self::getPageImage( $context->getTitle() );
		if ( !$imageFile ) {
			// The page has no image
			return true;
		}

		$thumbSetting = $context->getUser()->getOption(
			'thumbsize',
			$wgDefaultUserOptions['thumbsize']
		);
		$thumbSize = $wgThumbLimits[$thumbSetting];

		$thumb = $imageFile->transform( array( 'width' => $thumbSize ) );
		if ( !$thumb ) {
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

		return true;
	}

	/**
	 * ApiOpenSearchSuggest hook handler, enhances ApiOpenSearch results with this extension's data
	 *
	 * @param array[] &$results
	 * @return bool
	 */
	public static function onApiOpenSearchSuggest( array &$results ) {
		global $wgPageImagesExpandOpenSearchXml;

		if ( !$wgPageImagesExpandOpenSearchXml || !count( $results ) ) {
			return true;
		}

		$pageIds = array_keys( $results );
		$data = self::getImages( $pageIds, 50 );
		foreach ( $pageIds as $id ) {
			if ( isset( $data[$id]['thumbnail'] ) ) {
				$results[$id]['image'] = $data[$id]['thumbnail'];
			} else {
				$results[$id]['image'] = null;
			}
		}

		return true;
	}

	/**
	 * SpecialMobileEditWatchlist::images hook handler, adds images to mobile watchlist A-Z view
	 *
	 * @param IContextSource $context
	 * @param array[] $watchlist
	 * @param array[] &$images
	 * @return bool Always true
	 */
	public static function onSpecialMobileEditWatchlist_images( IContextSource $context, array $watchlist,
		array &$images
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
	 * @param int[] $pageIds
	 * @param int $size
	 *
	 * @return array[]
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

		if ( defined( 'ApiResult::META_CONTENT' ) ) {
			return (array)$api->getResult()->getResultData( array( 'query', 'pages' ),
				array( 'Strip' => 'base' ) );
		} else {
			$data = $api->getResultData();
			if ( isset( $data['query']['pages'] ) ) {
				return $data['query']['pages'];
			}
			return array();
		}
	}

}
