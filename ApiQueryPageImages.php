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
		foreach ( $res as $row ) {
			$vals = array();
			if ( $row->pp_propname == 'has_photos' ) {
				$vals['hasphotos'] = '';
			} elseif ( $row->pp_propname == 'page_image' ) {
				//$title = Title::makeTitle( NS_FILE,  );
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
			if ( count( $vals ) ) {
				$this->addPageSubItem( $row->pp_page, $vals );
			}
		}
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

	public function getVersion() {
		return '$Id$';
	}
}