<?php

namespace PageImages\Job;

use Job;
use MediaWiki\MediaWikiServices;
use MWExceptionHandler;
use RefreshLinks;
use Title;

class InitImageDataJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'InitImageDataJob', $title, $params );
	}

	public function run() {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		foreach ( $this->params['page_ids'] as $id ) {
			try {
				RefreshLinks::fixLinksFromArticle( $id );
				$lbFactory->waitForReplication();
			} catch (\Exception $e) {
				// There are some broken pages out there that just don't parse.
				// Log it and keep on trucking.
				MWExceptionHandler::logException( $e );
			}
		}
	}
}
