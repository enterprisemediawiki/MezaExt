(function() {

	var actions = [ "check", "uncheck" ];
	var radiotypes = [ 'deletesrc', 'redirectsrc', 'donothingsrc' ];
	var tables = [ 'conflicting', 'identical', 'unique' ];

	for( var i = 0; i < actions.length; i++ ) {
		for( var j = 0; j < tables.length; j++ ) {
			var action = actions[i];
			var actionBool = action === "check" ? true : false;
			var table = tables[j];

			$("#dotransfer-" + table + "-" + action + "-all").click( function( event ){
				event.preventDefault();
				$(".dotransfer-unique").prop( "checked", actionBool );
			});
		}
	}

	for( var i = 0; i < radiotypes.length; i++ ) {
		for( var j = 0; j < tables.length; j++ ) {
			var radiotype = radiotypes[i];
			var table = tables[j];
			$( "#srcaction-" + radiotype + "-" + table + "-check-all" ).click( function( event ) {
				event.preventDefault()
				$(".srcaction-" + radiotype + '-' + table).prop( "checked", true );
			});
		}
	}

})();
