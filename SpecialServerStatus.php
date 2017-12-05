<?php

class SpecialServerStatus extends SpecialPage {

	public $mMode;

	public function __construct() {
		parent::__construct(
			"ServerStatus", //
			"viewserverstatus",  // rights required to view
			true // show in Special:SpecialPages
		);
	}

	function execute( $parser = null ) {

		$webRequest = $this->getRequest();
		$requestedMode = $webRequest->getVal( 'mode', 'httpdstatus' );

		$modes = [ 'httpdstatus', 'httpdinfo', 'phpinfo' ];

		$headerLinks = [];
		foreach ( $modes as $mode ) {
			$linkText = wfMessage( 'serverstatus-mode-' . $mode );

			if ( $mode === $requestedMode ) {
				$headerLinks[] = Xml::element( 'strong', null, $linkText );
			}
			else {
				$headerLinks[] = Linker::link(
					$this->getPageTitle(),
					$linkText,
					[], // custom attributes
					[ 'mode' => $mode ]
				);
			}
		}

		$header = "<div style='background-color:#ddd; padding: 10px; font-weight: bold;'>";
		$header .= implode( ' | ', $headerLinks );
		$header .= '</div>';

		switch ( $requestedMode ) {
			case 'httpdinfo':
				$body = file_get_contents( 'http://127.0.0.1:8090/server-info' );
				$submodes = [
					'config'    => '<a href="?config">Configuration Files</a>',
					'server'    => '<a href="?server">Server Settings</a>'
					'list'      => '<a href="?list">Module List</a>'
					'hooks'     => '<a href="?hooks">Active Hooks</a>'
					'providers' => '<a href="?providers">Available Providers</a>'
				];
				foreach ( $submodes as $submode => $currentLink ) {
					$body = str_replace(
						$currentLink,
						Linker::link(
							this->getPageTitle(),
							"Configuration Files",
							[], // custom attributes
							[ 'mode' => 'httpdinfo', 'submode' => $submode ]
						),
						$body
					);
				}
				break;

			case 'phpinfo':
				ob_start();
				phpinfo();
				$body = ob_get_contents();
				ob_clean();
				break;

			default:
				$body = file_get_contents( 'http://127.0.0.1:8090/server-status' );
				break;
		}

		$output = $this->getOutput();
		$output->addHTML( $header . $body );

	}

}
