<?php
class MezaTransferPageJob extends Job {

	public $pageListForDump = '/opt/data-meza/mw-temp/mezaExt-TransferPages-pagelist';
	public $pageDumpOutputXML = '/opt/data-meza/mw-temp/mezaExt-TransferPages-pageDumpOutputXML';
	public $maintDir = '/opt/htdocs/mediawiki/maintenance/';

	public function __construct( Title $title, array $params ) {

		parent::__construct( 'mezaTransferPage', $title, $params );
	}

	public function run() {
		$unique = uniqid( '', true ); // unique enough
		$this->pageListForDump .= '-' . $unique;
		$this->pageDumpOutputXML .= '-' . $unique;

		$page = $this->title->getFullText();
		$srcWiki = $this->params['src'];
		$destWiki = $this->params['dest'];
		$srcAction = $this->params['srcAction'];

		trigger_error( "User " . exec( "whoami" ) . " running TransferPages: page=$page, src=$srcWiki, dest=$destWiki, srcAtion=$srcAction" );

		// remove existing files
		if ( file_exists( $this->pageListForDump ) ) {
			unlink( $this->pageListForDump );
		}
		if ( file_exists( $this->pageDumpOutputXML ) ) {
			unlink( $this->pageDumpOutputXML );
		}

		// create file listing which page to dump
		file_put_contents( $this->pageListForDump, $page );

		// FIXME echo is probably not the preferred way to do this but I want to
		// make sure any issues encountered get reported. Hopefully they'd show
		// in error logs but this is extra precaution since these actions are
		// highly obscured from view: user action creates a job, job runs
		// sometime later, job calls shell_exec. Seems like a recipe for missing
		// something important.

		// dumpBackup.php
		echo shell_exec(
			"WIKI=$srcWiki php " .
			"{$this->maintDir}dumpBackup.php " .
			"--full " .
			"--logs " .
			"--uploads " .
			"--include-files " .
			"--pagelist={$this->pageListForDump} " .
			"> {$this->pageDumpOutputXML}"
		);

		// importDump.php
		echo shell_exec(
			"WIKI=$destWiki php " .
			"{$this->maintDir}importDump.php " .
			"--no-updates " .
			"--username-prefix=\"\" " .
			"--uploads " .
			"--debug " .
			"--report=100 " .
			"< {$this->pageDumpOutputXML}"
		);

		$this->transferWatchers();

		RefreshLinks::fixLinksFromArticle( $this->title->getArticleID() );

		// perform source post-transfer action (delete, redirect, etc)
		if ( $srcAction === 'deletesrc' ) {
			// $this->deletePageFromSource( $title, $srcWiki );
		} elseif ( $srcAction === 'redirectsrc' ) {
			// $this->redirectSourceToDest( $title, $srcWiki, $destWiki );
		}
		// else do nothing

		// cleanup files
		unlink( $this->pageListForDump );
		unlink( $this->pageDumpOutputXML );

		return true;
	}

	public function genUniqueKey ( $row ) {
		return $row['wl_user'] . '-' . $row['wl_namespace'] . '-' . $row['wl_title'];
	}

	public function transferWatchers () {

		$destWikiDatabase = $this->getWikiDbConfig( $destWiki )['database'];

		// establish connection with destination wiki database
		$destDatabase = wfGetDB( DB_MASTER, [], $destWikiDatabase );

		$srcDatabase = wfGetDB( DB_MASTER );

		//
		// Select and process data from dest wiki
		//
		$destWatchesResult = $destDatabase->select(
			'watchlist',
			[ 'wl_user', 'wl_namespace', 'wl_title', 'wl_notificationtimestamp' ],
			[ 'wl_namespace' => $this->title->getNamespace(), 'wl_title' => $this->title->getDBkey() ],
			__METHOD__
		);
		$destWatchesHashTable = [];
		while ( $row = $destWatchesResult->fetchRow() ) {
			// create a fast way to lookup if watches found on destination wiki
			$destWatchesHashTable[$this->genUniqueKey( $row )] = [
				'wl_notificationtimestamp' => $row['wl_notificationtimestamp']
			];
		}

		//
		// Select and process data from the source wiki
		//
		$srcWatchesResult = $srcDatabase->select(
			'watchlist',
			[ 'wl_user', 'wl_namespace', 'wl_title', 'wl_notificationtimestamp' ],
			[ 'wl_namespace' => $this->title->getNamespace(), 'wl_title' => $this->title->getDBkey() ],
			__METHOD__
		);
		$watchesToInsert = [];
		$watchesToUpdate = [];
		while ( $row = $srcWatchesResult->fetchRow() ) {
			$uniqueKey = $this->genUniqueKey( $row );

			// If this user+namespace+page doesn't have a watchlist item on the dest
			// wiki, then simply insert the src wiki data into the dest wiki
			if ( ! isset( $destWatchesHashTable[$uniqueKey] ) ) {
				$watchesToInsert[] = $row;

			// Otherwise, determine which wiki's wl_notificationtimestamp to keep
			} else {

				$destTimestamp = $destWatchesHashTable[$uniqueKey]['wl_notificationtimestamp'];
				$srcTimestamp = $row['wl_notificationtimestamp'];

				// Timestamps are the same, so do nothing and leave destination as-is.
				// This is extremely unlikely with actual timestamps, but if the user
				// has seen the latest version on both wikis then the timestamp will be
				// NULL on both.
				if ( $destTimestamp === $srcTimestamp ) {
					continue;

				// If one is NULL at this point (we know they're not both NULL), then
				// set the other as the notification timestamp
				} elseif ( is_null( $destTimestamp ) ) {
					$ts = $srcTimestamp;
				} elseif ( is_null( $srcTimestamp ) ) {
					$ts = $destTimestamp;

				// Set the timestamp to the older one (missing more changes to the page)
				} else {
					$ts = $destTimestamp > $srcTimestamp ? $srcTimestamp : $destTimestamp;
				}

				$row['wl_notificationtimestamp'] = $ts;
				$watchesToUpdate[] = $row;
			}
		}

		// insert and update destination wiki watchlist if those lists have anything in
		// them
		if ( count( $watchesToInsert ) ) {
			$destWikiDatabase->insert(
				'watchlist',
				$watchesToInsert,
				__METHOD__
			);
		}
		if ( count( $watchesToUpdate ) ) {
			$destWikiDatabase->update(
				'watchlist',
				$watchesToUpdate,
				[], // conditions
				__METHOD__
			);
		}

	}

	// taken from meza unify user script
	protected function getWikiDbConfig ( $wikiID ) {

		global $m_htdocs, $wgDBuser, $wgDBpassword;

		include "$m_htdocs/wikis/$wikiID/config/preLocalSettings.php";

		if ( isset( $mezaCustomDBname ) ) {
			$wikiDBname = $mezaCustomDBname;
		} else {
			$wikiDBname = "wiki_$wikiID";
		}

		$wikiDBuser = isset( $mezaCustomDBuser ) ? $mezaCustomDBuser : $wgDBuser;
		$wikiDBpass = isset( $mezaCustomDBpass ) ? $mezaCustomDBpass : $wgDBpassword;

		return [
			'id' => $wikiID,
			'database' => $wikiDBname,
			'user' => $wikiDBuser,
			'password' => $wikiDBpass
		];

	}

}
