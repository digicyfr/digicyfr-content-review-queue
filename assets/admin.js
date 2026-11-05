( function () {
	'use strict';
	var result = document.querySelector( '.dcrq-result' );
	if ( result ) {
		window.addEventListener( 'load', function () {
			window.setTimeout( function () { result.focus(); }, 100 );
		} );
	}
	function submitAction( button ) {
		var box = button.closest( '.dcrq-controls' );
		if ( ! box ) { return; }
		var action = button.getAttribute( 'data-dcrq-action' );
		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = box.getAttribute( 'data-dcrq-endpoint' );
		var values = {
			action: 'dcrq_' + action,
			post_id: box.getAttribute( 'data-dcrq-post' ),
			_dcrq_nonce: button.getAttribute( 'data-dcrq-nonce' ),
			uuid: button.getAttribute( 'data-dcrq-uuid' ),
			due: box.querySelector( '[data-dcrq-due]' ).value,
			unschedule: box.querySelector( '[data-dcrq-unschedule]' ).checked ? '1' : '0',
			assignee: box.querySelector( '[data-dcrq-assignee]' ).value,
			note: box.querySelector( '[data-dcrq-note]' ).value,
			from_queue: box.getAttribute( 'data-dcrq-from-queue' ) || '0'
		};
		Object.keys( values ).forEach( function ( key ) {
			var field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = key;
			field.value = values[ key ];
			form.appendChild( field );
		} );
		document.body.appendChild( form );
		form.submit();
	}
	document.addEventListener( 'click', function ( event ) {
		var button = event.target instanceof Element ? event.target.closest( '[data-dcrq-action]' ) : null;
		if ( button ) {
			event.preventDefault();
			submitAction( button );
		}
	} );
	document.addEventListener( 'change', function ( event ) {
		if ( ! ( event.target instanceof Element ) ) { return; }
		var box = event.target.closest( '.dcrq-controls' );
		if ( ! box ) { return; }
		var due = box.querySelector( '[data-dcrq-due]' );
		var unschedule = box.querySelector( '[data-dcrq-unschedule]' );
		if ( event.target === due && due.value ) {
			unschedule.checked = false;
		} else if ( event.target === unschedule && unschedule.checked ) {
			due.value = '';
		}
	} );
} )();
