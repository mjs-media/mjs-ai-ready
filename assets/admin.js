/* Settings → AI ready: add and remove repeating rows. */
document.querySelectorAll( '.mjsair-repeater' ).forEach( function ( rep ) {
	var rows = rep.querySelector( '.mjsair-rows' );
	var tpl = rep.querySelector( 'template' );
	var next = rows.children.length;
	rep.querySelector( '.mjsair-add' ).addEventListener( 'click', function () {
		var html = tpl.innerHTML.replace( /__i__/g, String( next++ + 1000 ) );
		rows.insertAdjacentHTML( 'beforeend', html );
		var first = rows.lastElementChild.querySelector( 'input[type=text], textarea' );
		if ( first ) {
			first.focus();
		}
	} );
	rep.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'mjsair-remove' ) ) {
			e.target.closest( '.mjsair-row' ).remove();
		}
	} );
} );
