<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = dirname( dirname( dirname( __FILE__ ) ) );
}
require_once( "$IP/maintenance/Maintenance.php" );

class InitImageData extends Maintenance {
	const BATCH_SIZE = 100;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Initializes PageImages data';
		$this->addOption( 'namespaces', 'Comma-separated list of namespace(s) to refresh', false, true );
		$this->addOption( 'earlier-than', 'Run only on pages earlier than this timestamp', false, true );
	}

	public function execute() {
		global $wgPageImagesNamespaces;

		$id = 0;

		do {
			$tables = array( 'page', 'imagelinks' );
			$conds = array(
				"page_id > $id",
				'il_from IS NOT NULL'
			);
			$fields = array( 'page_id' );
			$joinConds = array( 'imagelinks' => array(
				'LEFT JOIN', 'page_id = il_from',
			) );

			if ( $this->hasOption( 'namespaces' ) ) {
				$ns = explode( ',', $this->getOption( 'namespaces' ) );
				$conds['page_namespace'] = $ns;
			} else {
				$conds['page_namespace'] = $wgPageImagesNamespaces;
			}
			if ( $this->hasOption( 'earlier-than' ) ) {
				$conds[] = "page_touched < '{$this->getOption( 'earlier-than' )}'";
			}
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select( $tables, $fields, $conds, __METHOD__,
				array( 'LIMIT' => self::BATCH_SIZE, 'ORDER_BY' => 'page_id', 'GROUP BY' => 'page_id' ),
				$joinConds
			);
			foreach ( $res as $row ) {
				$id = $row->page_id;
				RefreshLinks::fixLinksFromArticle( $id );
			}
			$this->output( "$id\n" );
			wfWaitForSlaves();
		} while ( $res->numRows() );
		$this->output( "done\n" );
	}
}

$maintClass = 'InitImageData';
require_once( DO_MAINTENANCE );
