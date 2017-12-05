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
