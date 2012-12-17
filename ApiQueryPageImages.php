<?php

class ApiQueryPageImages extends ApiQueryBase {
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pi' );
	}

	public function execute() {
		wfProfileIn( __METHOD__ );
		$titles = $this->getPageSet()->getGoodTitles();
		if ( count( $titles ) == 0 ) {
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

		$this->addTables( 'page_props' );
		$this->addFields( array( 'pp_page', 'pp_propname', 'pp_value' ) );
		$this->addWhere( array( 'pp_page' => array_keys( $titles ), 'pp_propname' => 'page_image' ) );
		if ( isset( $params['continue'] ) ) {
			// is_numeric() accepts floats, so...
			if ( intval( $params['continue'] ) == $params['continue'] ) {
				$this->addWhere( 'pp_page >= ' . intval( $params['continue'] ) );
			} else {
				$this->dieUsage( 'Invalid continue param. You should pass the original value returned by the previous query' , '_badcontinue' );
			}
		}
		$this->addOption( 'ORDER BY', 'pp_page' );
		$this->addOption( 'LIMIT', $limit + 1 );

		wfProfileIn( __METHOD__ . '-select' );
		$res = $this->select( __METHOD__ );
		wfProfileOut( __METHOD__ . '-select' );

		wfProfileIn( __METHOD__ . '-results' );
		$count = 0;
		foreach ( $res as $row ) {
			$pageId = $row->pp_page;
			if ( ++$count > $limit ) {
				$this->setContinueEnumParameter( 'continue', $pageId );
				echo 'break';
				break;
			}
			$vals = array();
			if ( isset( $prop['thumbnail'] ) ) {
				$file = wfFindFile( $row->pp_value );
				if ( $file ) {
					$thumb = $file->transform( array( 'width' => $size, 'height' => $size ) );
					if ( $thumb ) {
						$vals['thumbnail'] = array(
							'source' => wfExpandUrl( $thumb->getUrl(), PROTO_CURRENT ),
							'width' => $thumb->getWidth(),
							'height' => $thumb->getHeight(),
						);
					}
				}
			}
			if ( isset( $prop['name'] ) ) {
				$vals['pageimage'] = $row->pp_value;
			}
			$fit = $this->getResult()->addValue( array( 'query', 'pages' ), $pageId, $vals );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'continue', $pageId );
				break;
			}
		}
		wfProfileOut( __METHOD__ . '-results' );

		wfProfileOut( __METHOD__ );
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
			'thumbsize' => 'Thumbnail width',
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