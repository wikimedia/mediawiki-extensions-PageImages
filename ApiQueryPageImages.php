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

		$this->addTables( 'page_images' );
		$this->addFields( array( 'pi_page' ) );
		$propMapping = array( 'thumbnail' => 'pi_thumbnail', 'imagecount' => 'pi_images', 'totalscore' => 'pi_total_score' );
		foreach ( $propMapping as $apiName => $dbName ) {
			$this->addFieldsIf( $dbName, isset( $prop[$apiName] ) );
		}
		$this->addWhere( array( 'pi_page' => array_keys( $titles ) ) );
		if ( isset( $params['continue'] ) ) {
			// is_numeric() accepts floats, so...
			if ( preg_match( '/^\d+$/', $params['continue'] ) ) {
				$this->addWhere( 'pi_page >= ' . intval( $params['continue'] ) );
			} else {
				$this->dieUsage( 'Invalid continue param. You should pass the original value returned by the previous query' , '_badcontinue' );
			}
		}
		$this->addOption( 'ORDER BY', 'pi_page' );
		$this->addOption( 'LIMIT', $limit + 1 );

		$res = $this->select( __METHOD__ );
		foreach ( $res as $row ) {
			$vals = array();
			if ( isset( $prop['thumbnail'] ) ) {
				$file = wfFindFile( $row->pi_thumbnail );
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
			if ( isset( $prop['imagecount'] ) ) {
				$vals['imagecount'] = $row->pi_images;
			}
			if ( isset( $prop['totalscore'] ) ) {
				$vals['totalscore'] = $row->pi_total_score;
			}
			$fit = $this->getResult()->addValue( array( 'query', 'pages' ), $row->pi_page, $vals );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'continue', $row->pi_page );
			}
		}
	}

	public function getDescription() {
		return 'Returns information about images on the page such as thumbnail and presence of photos.';
	}

	public function getAllowedParams() {
		return array(
			'prop' => array(
				ApiBase::PARAM_TYPE => array( 'thumbnail', 'imagecount', 'totalscore' ),
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'thumbnail',
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
				' imagecount - Number of distinct illustrations on page',
				' totalscore - Sum of image scores',
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