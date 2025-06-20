jQuery( function($){
	if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
		$('#duwewp_disableblocks_block_selector').select2({
			placeholder: 'Select blocks to disable...',
			allowClear: true
		});
	}
} );