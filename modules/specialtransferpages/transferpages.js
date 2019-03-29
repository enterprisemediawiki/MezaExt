( function () {

	var actions = [ 'check', 'uncheck' ],
		radiotypes = [ 'deletesrc', 'redirectsrc', 'donothingsrc' ],
		tables = [ 'conflicting', 'identical', 'unique' ],
		clickerFactory = function ( selector, checked ) {
			return function ( event ) {
				if ( $(this).prop( 'tagName' ) === 'A' ) {
					event.preventDefault();
				}
				$( selector ).prop( 'checked', checked );
			};
		},
		clearAllFactory = function ( groupName, pageTable ) {
			return function ( event ) {
				// if the check-all/clear-all "buttons" are links not radios,
				// then this is sort of pointless. I don't think it'll hurt
				// anything though.
				$( groupName + '-' + pageTable + '-all' ).prop( 'checked', false );
			};
		},
		i,
		j,
		actionBool;

	for ( j = 0; j < tables.length; j++ ) {
		for ( i = 0; i < actions.length; i++ ) {
			actionBool = actions[ i ] === 'check';

			$( '#dotransfer-' + tables[ j ] + '-' + actions[ i ] + '-all' ).click(
				clickerFactory(
					'.dotransfer-' + tables[ j ],
					actionBool
				)
			);
		}

		$( '.dotransfer-' + tables[ j ] ).click(
			clearAllFactory( 'dotransfer', tables[ j ] )
		);
	}

	for ( j = 0; j < tables.length; j++ ) {
		for ( i = 0; i < radiotypes.length; i++ ) {
			$( '#srcaction-' + radiotypes[ i ] + '-' + tables[ j ] + '-check-all' ).click(
				clickerFactory(
					'.srcaction-' + radiotypes[ i ] + '-' + tables[ j ],
					true
				)
			);
		}

		$( '.srcaction-' + tables[ j ] ).click(
			clearAllFactory( 'srcaction', tables[ j ] )
		);
	}

}() );
