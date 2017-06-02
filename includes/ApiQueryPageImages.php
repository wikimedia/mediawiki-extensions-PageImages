<?php

/**
 * Expose image information for a page via a new prop=pageimages API.
 *
 * @see https://www.mediawiki.org/wiki/Extension:PageImages#API
 *
 * @license WTFPL 2.0
 * @author Max Semenik
 * @author Ryan Kaldari
 * @author Yuvi Panda
 * @author Sam Smith
 */
class ApiQueryPageImages extends ApiQueryBase {

	/**
	 * @const API parameter value for free images
	 */
	const PARAM_LICENSE_FREE = 'free';

	/**
	 * @const API parameter value for images with any type of license
	 */
	const PARAM_LICENSE_ANY = 'any';

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 */
	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pi' );
	}

	/**
	 * Gets the set of titles to get page images for.
	 *
	 * Note well that the set of titles comprises the set of "good" titles
	 * (see {@see ApiPageSet::getGoodTitles}) union the set of "missing"
	 * titles in the File namespace that might correspond to foreign files.
	 * The latter are included because titles in the File namespace are
	 * expected to be found with {@see wfFindFile}.
	 *
	 * @return Title[] A map of page ID, which will be negative in the case
	 *  of missing titles in the File namespace, to Title object
	 */
	protected function getTitles() {
		$pageSet = $this->getPageSet();
		$titles = $pageSet->getGoodTitles();

		// T98791: We want foreign files to be treated like local files
		// in #execute, so include the set of missing filespace pages,
		// which were initially rejected in ApiPageSet#execute.
		$missingTitles = $pageSet->getMissingTitlesByNamespace();
		$missingFileTitles = isset( $missingTitles[NS_FILE] )
			? $missingTitles[NS_FILE]
			: [];

		// $titles is a map of ID to title object, which is ideal,
		// whereas $missingFileTitles is a map of title text to ID.
		$missingFileTitles = array_map( function ( $text ) {
			return Title::newFromText( $text, NS_FILE );
		}, array_flip( $missingFileTitles ) );

		// N.B. We can't use array_merge here as it doesn't preserve
		// keys.
		foreach ( $missingFileTitles as $id => $title ) {
			$titles[$id] = $title;
		}

		return $titles;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$prop = array_flip( $params['prop'] );
		if ( !count( $prop ) ) {
			if ( is_callable( [ $this, 'dieWithError' ] ) ) {
				$this->dieWithError(
					[ 'apierror-paramempty', $this->encodeParamName( 'prop' ) ], 'noprop'
				);
			} else {
				$this->dieUsage( 'No properties selected', '_noprop' );
			}
		}

		$allTitles = $this->getTitles();

		if ( count( $allTitles ) === 0 ) {
			return;
		}

		// Find the offset based on the continue param
		$offset = 0;
		if ( isset( $params['continue'] ) ) {
			// Get the position (not the key) of the 'continue' page within the
			// array of titles. Set this as the offset.
			$pageIds = array_keys( $allTitles );
			$offset = array_search( intval( $params['continue'] ), $pageIds );
			// If the 'continue' page wasn't found, die with error
			$this->dieContinueUsageIf( !$offset );
		}

		$limit = $params['limit'];
		// Slice the part of the array we want to find images for
		$titles = array_slice( $allTitles, $offset, $limit, true );

		// Get the next item in the title array and use it to set the continue value
		$nextItemArray = array_slice( $allTitles, $offset + $limit, 1, true );
		if ( $nextItemArray ) {
			$this->setContinueEnumParameter( 'continue', key( $nextItemArray ) );
		}

		// Find any titles in the file namespace so we can handle those separately
		$filePageTitles = [];
		foreach ( $titles as $id => $title ) {
			if ( $title->inNamespace( NS_FILE ) ) {
				$filePageTitles[$id] = $title;
				unset( $titles[$id] );
			}
		}

		$size = $params['thumbsize'];
		// Extract page images from the page_props table
		if ( count( $titles ) > 0 ) {
			$this->addTables( 'page_props' );
			$this->addFields( [ 'pp_page', 'pp_propname', 'pp_value' ] );
			$this->addWhere( [ 'pp_page' => array_keys( $titles ),
				'pp_propname' => self::getPropNames( $params['license'] ) ] );

			$res = $this->select( __METHOD__ );

			$buffer = [];
			$propNameAny = PageImages::getPropName( false );
			foreach ( $res as $row ) {
				$pageId = $row->pp_page;
				if ( !array_key_exists( $pageId, $buffer ) || $row->pp_propname === $propNameAny ) {
					$buffer[$pageId] = $row;
				}
			}

			foreach ( $buffer as $pageId => $row ) {
				$fileName = $row->pp_value;
				$this->setResultValues( $prop, $pageId, $fileName, $size );
			}

		} // End page props image extraction

		// Extract images from file namespace pages. In this case we just use
		// the file itself rather than searching for a page_image. (Bug 50252)
		foreach ( $filePageTitles as $pageId => $title ) {
			$fileName = $title->getDBkey();
			$this->setResultValues( $prop, $pageId, $fileName, $size );
		}
	}

	/**
	 * Get property names used in page_props table
	 *
	 * If the license is free, then only the free property name will be returned,
	 * otherwise both free and non-free property names will be returned. That's
	 * because we save the image name only once if it's free and the best image.
	 *
	 * @param string $license
	 * @return string|array
	 */
	protected static function getPropNames( $license ) {
		if ( $license === self::PARAM_LICENSE_FREE ) {
			return PageImages::getPropName( true );
		}
		return [ PageImages::getPropName( true ), PageImages::getPropName( false ) ];
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	/**
	 * For a given page, set API return values for thumbnail and pageimage as needed
	 *
	 * @param array $prop The prop values from the API request
	 * @param int $pageId The ID of the page
	 * @param string $fileName The name of the file to transform
	 * @param int $size The thumbsize value from the API request
	 */
	protected function setResultValues( array $prop, $pageId, $fileName, $size ) {
		$vals = [];
		if ( isset( $prop['thumbnail'] ) || isset( $prop['original'] ) ) {
			$file = wfFindFile( $fileName );
			if ( $file ) {
				$vals['thumbnail'] = [];

				if ( isset( $prop['thumbnail'] ) ) {
					$thumb = $file->transform( [ 'width' => $size, 'height' => $size ] );
					if ( $thumb && $thumb->getUrl() ) {
						// You can request a thumb 1000x larger than the original
						// which (in case of bitmap original) will return a Thumb object
						// that will lie about its size but have the original as an image.
						$reportedSize = $thumb->fileIsSource() ? $file : $thumb;
						$vals['thumbnail'] = [
							'source' => wfExpandUrl( $thumb->getUrl(), PROTO_CURRENT ),
							'width' => $reportedSize->getWidth(),
							'height' => $reportedSize->getHeight(),
						];
					}
				}

				if ( isset( $prop['original'] ) ) {
					$original_url = wfExpandUrl( $file->getUrl(), PROTO_CURRENT );

					$vals['original'] = [
						'source' => $original_url,
						'width' => $file->getWidth(),
						'height' => $file->getHeight()
					];

					// DEPRECATED: The original image URL was originally provided as a property of
					// the "thumbnail" object, but we now provide the original image info in its own
					// object on the same level as the thumbnail.
					// Remove this functionality, and the warning, after a reasonable amount of time
					// has passed since December 2016.
					if ( is_callable( [ $this, 'addWarning' ] ) ) {
						$this->addWarning( 'apiwarn-query+pageimages-original-in-thumbnail-deprecated' );
					} else {
						$this->setWarning( "The original image's source URL is now provided with " .
							"its dimensions in its own property for the page object. It will be " .
							"removed from the 'thumbnail' property in a future release." );
					}
					$vals['thumbnail']['original'] = $original_url;
				}
			}
		}

		if ( isset( $prop['name'] ) ) {
			$vals['pageimage'] = $fileName;
		}

		$this->getResult()->addValue( [ 'query', 'pages' ], $pageId, $vals );
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return 'Returns information about images on the page such as thumbnail and presence of photos.';
	}

	public function getAllowedParams() {
		return [
			'prop' => [
				ApiBase::PARAM_TYPE => [ 'thumbnail', 'name', 'original' ],
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'thumbnail|name',
			],
			'thumbsize' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_DFLT => 50,
			],
			'limit' => [
				ApiBase::PARAM_DFLT => 50,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => 50,
				ApiBase::PARAM_MAX2 => 100,
			],
			'license' => [
				ApiBase::PARAM_TYPE => [ self::PARAM_LICENSE_FREE, self::PARAM_LICENSE_ANY ],
				ApiBase::PARAM_ISMULTI => false,
				ApiBase::PARAM_DFLT => $this->getConfig()->get( 'PageImagesAPIDefaultLicense' ),
			],
			'continue' => [
				ApiBase::PARAM_TYPE => 'integer',
				/**
				 * @todo
				 * Once support for MediaWiki < 1.25 is dropped, just use
				 * ApiBase::PARAM_HELP_MSG directly
				 */
				defined( 'ApiBase::PARAM_HELP_MSG' )
					? ApiBase::PARAM_HELP_MSG : '' => 'api-help-param-continue',
			],
		];
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getParamDescription() {
		return [
			'prop' => [ 'What information to return',
				' thumbnail - URL and dimensions of image associated with page, if any',
				' name - image title',
				' original - URL and dimensions of the original image',
			],
			'thumbsize' => 'Maximum thumbnail dimension',
			'limit' => 'Properties of how many pages to return',
			'continue' => 'When more results are available, use this to continue',
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&prop=pageimages&titles=Albert%20Einstein&pithumbsize=100' =>
				'apihelp-query+pageimages-example-1',
		];
	}

	public function getHelpUrls() {
		return "https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageImages#API";
	}

}
