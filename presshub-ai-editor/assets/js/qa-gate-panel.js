/**
 * Refreshes the QA gate panel in the PressHub box after the block editor
 * saves or publishes (the save goes through REST, so the page does not
 * reload and the panel would show the review from before the save).
 *
 * @since 2.51.0
 */
( function () {
	'use strict';

	var cfg = window.presshubQaGatePanel || {};
	if ( ! cfg.ajaxUrl || ! cfg.postId || ! window.wp || ! window.wp.data || ! window.wp.data.select( 'core/editor' ) ) {
		return;
	}

	function refresh() {
		var slot = document.getElementById( 'presshub-qa-gate-panel-slot' );
		if ( ! slot || ! window.fetch ) {
			return;
		}
		var body = new window.FormData();
		body.append( 'action', 'presshub_ai_qa_gate_panel' );
		body.append( 'post_id', String( cfg.postId ) );
		body.append( '_ajax_nonce', cfg.nonce );
		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success && res.data && 'string' === typeof res.data.html ) {
					slot.innerHTML = res.data.html;
				}
			} )
			.catch( function () {} );
	}

	var wasSaving = false;
	window.wp.data.subscribe( function () {
		var editor = window.wp.data.select( 'core/editor' );
		var saving = editor.isSavingPost() && ! editor.isAutosavingPost();
		if ( wasSaving && ! saving ) {
			// The gate runs during the save; give the meta a moment, then reload the panel.
			window.setTimeout( refresh, 500 );
		}
		wasSaving = saving;
	} );
}() );
