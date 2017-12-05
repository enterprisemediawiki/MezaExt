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

		$linkInfo = [
			'httpdstatus' => 'Apache server status',
			'httpdinfo' => 'Apache server info',
			'phpinfo' => 'PHP info',
		];

		$header = "<div style='background-color:#ddd; padding: 10px; font-weight: bold;'>";
		foreach ( $linkInfo as $mode => $text ) {
			$header .= Linker:link(
				$this->getPageTitle(),
				$text,
				[], // custom attributes
				[ 'mode' => $mode ]
			);
		}
		$header .= '</div>';

		switch ( $requestMode ) {
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
