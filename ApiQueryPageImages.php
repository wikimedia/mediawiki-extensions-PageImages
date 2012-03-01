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
		$size = $params['thumbsize'];

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
		$this->addOption( 'ORDER BY', 'pp_page' );

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
			$fit = $this->getResult()->addValue( array( 'query', 'pages' ), $pageId, $data );
			$data = array();
		}
		if ( !$fit ) {
			$this->setContinueEnumParameter( 'continue', $pageId );
		}
		return $fit;
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
		);
	}

	public function getParamDescription() {
		return array(
			'prop' => array( 'What information to return',
				' thumbnail - URL and dimensions of image associated with page, if any',
				' hasphotos - whether this page contains photos'
			),
			'thumbsize' => 'Width of thumbnail image',
		);
	}

	public function getVersion() {
		return '$Id$';
	}
}