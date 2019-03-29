( function () {

	var actions = [ "check", "uncheck" ],
		radiotypes = [ 'deletesrc', 'redirectsrc', 'donothingsrc' ],
		tables = [ 'conflicting', 'identical', 'unique' ],
		clickerFactory = function ( selector, checked ) {
			return function ( event ) {
				event.preventDefault();
				$( selector ).prop( "checked", checked );
			};
		},
		i,
		j,
		action,
		actionBool,
		table;

	for ( i = 0; i < actions.length; i++ ) {
		for ( j = 0; j < tables.length; j++ ) {
			actionBool = action === "check" ? true : false;

			$( "#dotransfer-" + tables[j] + "-" + actions[i] + "-all" ).click(
				clickerFactory(
					".dotransfer-" + tables[j],
					actionBool
				)
			);
		}
	}

	for ( i = 0; i < radiotypes.length; i++ ) {
		for ( j = 0; j < tables.length; j++ ) {
			$( "#srcaction-" + radiotypes[i] + "-" + tables[j] + "-check-all" ).click(
				clickerFactory(
					".srcaction-" + radiotypes[i] + '-' + tables[j],
					true
				)
			);
		}
	}

}() );
