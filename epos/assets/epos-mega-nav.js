/**
 * EPOS mega-nav: set CSS var for dropdown top under the masthead (Kadence header height varies).
 */
( function () {
	'use strict';

	function updateMegaTop() {
		var masthead = document.getElementById( 'masthead' );
		if ( ! masthead ) {
			masthead = document.querySelector( '.site-header-wrap' );
		}
		if ( ! masthead ) {
			return;
		}
		var bottom = masthead.getBoundingClientRect().bottom;
		document.documentElement.style.setProperty( '--epos-mega-top', bottom + 'px' );
	}

	updateMegaTop();
	window.addEventListener( 'resize', updateMegaTop );
	window.addEventListener( 'load', updateMegaTop );

	if ( typeof ResizeObserver === 'function' ) {
		var masthead = document.getElementById( 'masthead' );
		if ( masthead ) {
			var ro = new ResizeObserver( updateMegaTop );
			ro.observe( masthead );
		}
	}
} )();
