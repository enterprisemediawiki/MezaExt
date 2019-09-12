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

		$this->transferWatchers( $destWiki );

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

	public function transferWatchers ($destWiki) {

		$destWikiDBname = $this->getWikiDbConfig( $destWiki )['database'];

		// establish connection with destination wiki database
		$destDatabase = wfGetDB( DB_MASTER, [], $destWikiDBname );

		$srcDatabase = wfGetDB( DB_REPLICA );

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

		$fields = [ 'wl_user', 'wl_namespace', 'wl_title', 'wl_notificationtimestamp' ];

		//
		// Select and process data from the source wiki
		//
		$srcWatchesResult = $srcDatabase->select(
			'watchlist',
			$fields,
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
				$watchesToInsert[] = $this->sanitizeRow( $row );

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
				// the other is the preferable notification timestamp
				} elseif ( is_null( $destTimestamp ) ) {
					$ts = $srcTimestamp;

				// if src is null at this point, then we'd want to set the value
				// to the dest wiki's...but it's already set that way so move on
				} elseif ( is_null( $srcTimestamp ) ) {
					continue;

				// Set the timestamp to the older src timestamp
				} elseif ( $destTimestamp > $srcTimestamp ) {
					$ts = $srcTimestamp;

				// the dest timestamp is older. keep using that and move on.
				} else {
					continue;
				}

				$row['wl_notificationtimestamp'] = $ts;
				$watchesToUpdate[] = $this->sanitizeRow( $row );
			}
		}

		// insert and update destination wiki watchlist. See doManualInsert()
		// for explanation why "manual" method is needed.
		foreach ( $watchesToInsert as $watch ) {
			$this->doManualInsert($watch, $destWikiDBname);
		}
		foreach ( $watchesToUpdate as $watch ) {
			$this->doManualUpdate( $watch, asdfasdfasdf );
		}
	}

	// taken from meza unify user script
	protected function getWikiDbConfig ( $wikiID ) {

		global $m_htdocs, $wgDBuser, $wgDBpassword;

		include "$m_htdocs/wikis/$wikiID/config/preLocalSettings.d/base.php";

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

	// without this for some reason you get an array like:
	// [ 'wl_user'       => 1,
	//    0              => 1,
	//    'wl_namespace' => 0
	//    1              => 0,
	//    'wl_title'     => 'Main_Page',
	//    2              => 'Main_Page', ... ]
	// In other words, you get the array indexes as well as the text keys. Why?
	// I forget if this is a PHP or MediaWiki thing. Either way, this cleans it.
	protected function sanitizeRow ( $row ) {
		if ( is_null( $row['wl_notificationtimestamp'] ) ) {
			$ts = null;
		} else {
			$ts = intval( $row['wl_notificationtimestamp'] );
		}

		return [
			'wl_user'                  => intval( $row['wl_user'] ),
			'wl_namespace'             => intval( $row['wl_namespace'] ),
			'wl_title'                 => $row['wl_title'],
			'wl_notificationtimestamp' => $ts,
		];
	}

	/**
	 * Do SQL UPDATE on destination wiki.
	 *
	 * For some reason doing this "manually" is required. In other words, we
	 * have to directly use mysqli rather than using the MediaWiki API. Using
	 * $destDatabase->insert( ... ) should work, but for some reason it was not
	 * writing to the database despite not throwing any errors and all
	 * indications being that data was written. Using mysqli directly does not
	 * have this issue.
	 *
	 * $destDatabase->insert( 'watchlist', $watch, __METHOD__ );
	 *
	 * Also, using $destDatabase->query( ... ) was attempted, but had the same
	 * issue.
	 *
	 * @param  Array $watch           Row of table "watchlist"
	 * @param  String $destWikiDBname Name of destination wiki database, e.g.
	 *                                'wiki_demo'
	 * @return null
	 */
	protected function doManualInsert ($watch, $destWikiDBname) {

		global $databaseServer, $mezaDatabaseUser, $mezaDatabasePassword;

		$db = new mysqli($databaseServer, $mezaDatabaseUser, $mezaDatabasePassword, $destWikiDBname);

		if($db->connect_errno > 0){
			die('Unable to connect to database [' . $db->connect_error . ']');
		}

		$sql = "
			INSERT INTO watchlist
			(wl_user, wl_namespace, wl_title, wl_notificationtimestamp)
			VALUES
			(?, ?, ?, ?)
		";

		$stmt = $db->prepare($sql);
		$stmt->bind_param(
			'iisi',
			$watch['wl_user'],
			$watch['wl_namespace'],
			$watch['wl_title'],
			$watch['wl_notificationtimestamp']
		);

		$stmt->execute();

		$stmt->close();
		$db->close();

	}

	/**
	 * This should work:
	 *
	 * $destDatabase->update(
	 *     'watchlist',
	 *     [ 'wl_notificationtimestamp' => $watchesToUpdate['wl_notificationtimestamp'] ],
	 *     [ // conditions
	 *         'wl_user' => $watchesToUpdate['wl_user'],
	 *         'wl_namespace' => $watchesToUpdate['wl_namespace'],
	 *         'wl_title' => $watchesToUpdate['wl_title'],
	 *     ],
	 *     __METHOD__
	 * );
	 *
	 * Buuuuuuuut...it doesn't. See doManualInsert above for explanation.
	 */
	protected function doManualUpdate ($watch, $destWikiDBname) {

		global $databaseServer, $mezaDatabaseUser, $mezaDatabasePassword;

		$db = new mysqli($databaseServer, $mezaDatabaseUser, $mezaDatabasePassword, $destWikiDBname);

		if($db->connect_errno > 0){
			die('Unable to connect to database [' . $db->connect_error . ']');
		}

		$sql = "
			UPDATE watchlist
			SET wl_notificationtimestamp = ?
			WHERE
				wl_user = ?
				AND wl_namespace = ?
				AND wl_title = ?
		";

		$stmt = $db->prepare($sql);
		$stmt->bind_param(
			'iiis',
			$watch['wl_notificationtimestamp'],
			$watch['wl_user'],
			$watch['wl_namespace'],
			$watch['wl_title'],
		);

		$stmt->execute();

		$stmt->close();
		$db->close();

	}
}
