<?php

class ApiQueryPageImages extends ApiQueryBase {
	private $count = 0, $limit;

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
			$this->dieUsage(  );
		}
		$size = $params['thumbsize'];
		$this->limit = $params['limit'];

		$this->addTables( 'page_props' );
		$this->addFields( array( 'pp_page', 'pp_propname', 'pp_value' ) );
		$propNames = array();
		$propMapping = array( 'hasphotos' => 'has_photos', 'thumbnail' => 'page_image' );
		foreach ( $propMapping as $apiName => $dbName ) {
			if ( isset( $prop[$apiName] ) ) {
				$propNames[] = $dbName;
			}
		}
		$this->addWhere( array( 'pp_page' => array_keys( $titles ), 'pp_propname' => $propNames ) );
		if ( isset( $params['continue'] ) ) {
			// is_numeric() accepts floats, so...
			if ( preg_match( '/^\d+$/', $params['continue'] ) ) {
				$this->addWhere( 'pp_page >= ' . intval( $params['continue'] ) );
			} else {
				$this->dieUsage( 'Invalid continue param. You should pass the original value returned by the previous query' , '_badcontinue' );
			}
		}
		$this->addOption( 'ORDER BY', 'pp_page' );
		$this->addOption( 'LIMIT', $this->limit * count( $prop ) + 1 );

		$res = $this->select( __METHOD__ );
		$lastId = 0;
		$vals = array();
		foreach ( $res as $row ) {
			if ( $lastId && $lastId != $row->pp_page && !$this->addData( $lastId, $vals ) ) {
				break;
			}
			$lastId = $row->pp_page;
			if ( $row->pp_propname == 'has_photos' ) {
				$vals['hasphotos'] = '';
			} elseif ( $row->pp_propname == 'page_image' ) {
				$file = wfFindFile( $row->pp_value );
				if ( $file ) {
					$thumb = $file->transform( array( 'width' => $size, 'height' => $size ) );
					if ( $thumb ) {
						$vals['thumb'] = array(
							'src' => wfExpandUrl( $thumb->getUrl(), PROTO_CURRENT ),
							'width' => $thumb->getWidth(),
							'height' => $thumb->getHeight(),
						);
					}
				}
			}
		}
		$this->addData( $lastId, $vals );
	}

	private function addData( $pageId, array &$data ) {
		$fit = true;
		if ( count( $data ) ) {
			$fit = ++$this->count <= $this->limit
				&& $this->getResult()->addValue( array( 'query', 'pages' ), $pageId, $data );
			$data = array();
		}
		if ( !$fit ) {
			$this->setContinueEnumParameter( 'continue', $pageId );
		}
		return $fit;
	}

	public function getDescription() {
		return 'Returns information about images on the page such as thumbnail and presence of photos.';
	}

	public function getAllowedParams() {
		return array(
			'prop' => array(
				ApiBase::PARAM_TYPE => array( 'thumbnail', 'hasphotos' ),
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'thumbnail|hasphotos',
			),
			'thumbsize' => array(
				ApiBase::PARAM_TYPE => 'integer',
				APiBase::PARAM_DFLT => 50,
			),
			'limit' => array(
				ApiBase::PARAM_DFLT => 1,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2,
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
				' hasphotos - whether this page contains photos'
			),
			'thumbsize' => 'Width of thumbnail image',
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
		return '$Id$';
	}
}