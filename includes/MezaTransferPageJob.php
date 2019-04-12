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

}
