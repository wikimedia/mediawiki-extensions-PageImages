<?php
/**
 * Expose image information for a page via a new prop=pageimages API.
 * See https://www.mediawiki.org/wiki/Extension:PageImages#API
 */
class ApiQueryPageImages extends ApiQueryBase {
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pi' );
	}

	public function execute() {
		wfProfileIn( __METHOD__ );
		$allTitles = $this->getPageSet()->getGoodTitles();
		if ( count( $allTitles ) == 0 ) {
			wfProfileOut( __METHOD__ );
			return;
		}
		$params = $this->extractRequestParams();
		$prop = array_flip( $params['prop'] );
		if ( !count( $prop ) ) {
			$this->dieUsage( 'No properties selected', '_noprop' );
		}
		$size = $params['thumbsize'];
		$limit = $params['limit'];

		// Find the offset based on the continue param
		$offset = 0;
		if ( isset( $params['continue'] ) ) {
			// Get the position (not the key) of the 'continue' page within the
			// array of titles. Set this as the offset.
			$pageIds = array_keys( $allTitles );
			$offset = array_search( intval( $params['continue'] ), $pageIds );
			// If the 'continue' page wasn't found, die with error
			if ( !$offset ) {
				$this->dieUsage( 'Invalid continue param. You should pass the original value returned by the previous query' , '_badcontinue' );
			}
		}

		// Slice the part of the array we want to find images for
		$titles = array_slice( $allTitles, $offset, $limit, true );

		// Get the next item in the title array and use it to set the continue value
		$nextItemArray = array_slice( $allTitles, $offset + $limit, 1, true );
		if ( $nextItemArray ) {
			$this->setContinueEnumParameter( 'continue', key( $nextItemArray ) );
		}

		// Find any titles in the file namespace so we can handle those separately
		$filePageTitles = array();
		foreach ( $titles as $id => $title ) {
			if ( $title->inNamespace( NS_FILE ) ) {
				$filePageTitles[$id] = $title;
				unset( $titles[$id] );
			}
		}

		// Extract page images from the page_props table
		if ( count( $titles ) > 0 ) {
			$this->addTables( 'page_props' );
			$this->addFields( array( 'pp_page', 'pp_propname', 'pp_value' ) );
			$this->addWhere( array( 'pp_page' => array_keys( $titles ), 'pp_propname' => PageImages::PROP_NAME ) );

			wfProfileIn( __METHOD__ . '-select' );
			$res = $this->select( __METHOD__ );
			wfProfileOut( __METHOD__ . '-select' );

			wfProfileIn( __METHOD__ . '-results-pages' );
			foreach ( $res as $row ) {
				$pageId = $row->pp_page;
				$fileName = $row->pp_value;
				$this->setResultValues( $prop, $pageId, $fileName, $size );
			}
			wfProfileOut( __METHOD__ . '-results-pages' );
		} // End page props image extraction

		// Extract images from file namespace pages. In this case we just use
		// the file itself rather than searching for a page_image. (Bug 50252)
		wfProfileIn( __METHOD__ . '-results-files' );
		foreach ( $filePageTitles as $pageId => $title ) {
			$fileName = $title->getDBkey();
			$this->setResultValues( $prop, $pageId, $fileName, $size );
		}
		wfProfileOut( __METHOD__ . '-results-files' );

		wfProfileOut( __METHOD__ );
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	/**
	 * For a given page, set API return values for thumbnail and pageimage as needed
	 *
	 * @param array $prop The prop values from the API request
	 * @param integer $pageId The ID of the page
	 * @param string $fileName The name of the file to transform
	 * @param integer $size The thumbsize value from the API request
	 */
	protected function setResultValues( $prop, $pageId, $fileName, $size ) {
		$vals = array();
		if ( isset( $prop['thumbnail'] ) ) {
			$file = wfFindFile( $fileName );
			if ( $file ) {
				$thumb = $file->transform( array( 'width' => $size, 'height' => $size ) );
				if ( $thumb && $thumb->getUrl() ) {
					$vals['thumbnail'] = array(
						'source' => wfExpandUrl( $thumb->getUrl(), PROTO_CURRENT ),
						'width' => $thumb->getWidth(),
						'height' => $thumb->getHeight()
					);
				}
			}
		}
		if ( isset( $prop['name'] ) ) {
			$vals['pageimage'] = $fileName;
		}
		$this->getResult()->addValue( array( 'query', 'pages' ), $pageId, $vals );
	}

	public function getDescription() {
		return 'Returns information about images on the page such as thumbnail and presence of photos.';
	}

	public function getAllowedParams() {
		return array(
			'prop' => array(
				ApiBase::PARAM_TYPE => array( 'thumbnail', 'name' ),
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'thumbnail|name',
			),
			'thumbsize' => array(
				ApiBase::PARAM_TYPE => 'integer',
				APiBase::PARAM_DFLT => 50,
			),
			'limit' => array(
				ApiBase::PARAM_DFLT => 1,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => 50,
				ApiBase::PARAM_MAX2 => 100,
			),
			'continue' => array(
				ApiBase::PARAM_TYPE => 'integer',
			),
		);
	}

	public function getParamDescription() {
		return array(
			'prop' => array( 'What information to return',
				' thumbnail - URL and dimensions of image associated with page, if any',
				' name - image title'
			),
			'thumbsize' => 'Maximum thumbnail dimension',
			'limit' => 'Properties of how many pages to return',
			'continue' => 'When more results are available, use this to continue',
		);
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
				array( 'code' => '_noprop', 'info' => 'At least one prop should be specified' ),
				array( 'code' => '_badcontinue', 'info' => 'Invalid continue param. You should pass the original value returned by the previous query' ),
			)
		);
	}

	public function getVersion() {
		return '';
	}
}
