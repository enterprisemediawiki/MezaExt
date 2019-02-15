<?php
class MezaTransferPageJob extends Job {

	public $pageListForDump = '/opt/data-meza/mw-temp/mezaExt-TransferPages-pagelist';
	public $pageDumpOutputXML = '/opt/data-meza/mw-temp/mezaExt-TransferPages-pageDumpOutputXML';
	public $maintDir = '/opt/htdocs/mediawiki/maintenance/';

	public function __construct( Title $title, array $params ) {

		parent::__construct( 'mezaTransferPage', $title, $params );
	}

	public function run() {

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

		// dumpBackup.php
		shell_exec(
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
		shell_exec(
			"WIKI=$destWiki php " .
			"{$this->maintDir}importDump.php " .
			"--no-updates " .
			"--username-prefix=\"\" " .
			"--uploads " .
			"--debug " .
			"--report=100 " .
			"< {$this->pageDumpOutputXML}"
		);

		// perform source post-transfer action (delete, redirect, etc)
		if ( $srcAction === 'deletesrc' ) {
			// $this->deletePageFromSource( $title, $srcWiki );
		} elseif ( $srcAction === 'redirectsrc' ) {
			// $this->redirectSourceToDest( $title, $srcWiki, $destWiki );
		}
		// else do nothing

		return true;
	}

}
