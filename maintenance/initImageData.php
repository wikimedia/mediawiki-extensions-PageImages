<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once ( "$IP/maintenance/Maintenance.php" );

use MediaWiki\MediaWikiServices;

/**
 * @license WTFPL 2.0
 * @author Max Semenik
 */
class InitImageData extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Initializes PageImages data';
		$this->addOption( 'namespaces',
			'Comma-separated list of namespace(s) to refresh', false, true );
		$this->addOption( 'earlier-than',
			'Run only on pages earlier than this timestamp', false, true );
		$this->addOption( 'start', 'Starting page ID', false, true );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		global $wgPageImagesNamespaces;

		$id = $this->getOption( 'start', 0 );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		do {
			$tables = [ 'page', 'imagelinks' ];
			$conds = [
				'page_id > ' . (int) $id,
				'il_from IS NOT NULL',
				'page_is_redirect' => 0,
			];
			$fields = [ 'page_id' ];
			$joinConds = [ 'imagelinks' => [
				'LEFT JOIN', 'page_id = il_from',
			] ];

			$dbr = wfGetDB( DB_SLAVE );
			if ( $this->hasOption( 'namespaces' ) ) {
				$ns = explode( ',', $this->getOption( 'namespaces' ) );
				$conds['page_namespace'] = $ns;
			} else {
				$conds['page_namespace'] = $wgPageImagesNamespaces;
			}
			if ( $this->hasOption( 'earlier-than' ) ) {
				$conds[] = 'page_touched < '
					. $dbr->addQuotes( $this->getOption( 'earlier-than' ) );
			}
			$res = $dbr->select( $tables, $fields, $conds, __METHOD__,
				[ 'LIMIT' => $this->mBatchSize, 'ORDER_BY' => 'page_id', 'GROUP BY' => 'page_id' ],
				$joinConds
			);
			foreach ( $res as $row ) {
				$id = $row->page_id;
				RefreshLinks::fixLinksFromArticle( $id );
				$lbFactory->waitForReplication();
			}
			$this->output( "$id\n" );
		} while ( $res->numRows() );
		$this->output( "done\n" );
	}
}

$maintClass = 'InitImageData';
require_once ( DO_MAINTENANCE );
