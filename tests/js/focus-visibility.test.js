import { revealCatalogueFocus } from '../../assets/src/panels/catalogue/focus-visibility';

describe( 'focus clear of the sticky selection bar', () => {
	let bar;
	let target;
	beforeEach( () => {
		document.body.innerHTML =
			'<div class="bulkcat-catalogue__main"><div class="bulkcat-selection" style="position:sticky;top:32px"><button id="action">Change</button></div><button id="row">Product</button></div>';
		bar = document.querySelector( '.bulkcat-selection' );
		target = document.getElementById( 'row' );
		bar.getBoundingClientRect = () => ( { top: 32, bottom: 112 } );
		target.getBoundingClientRect = () => ( { top: 48, bottom: 72 } );
		window.scrollBy = jest.fn();
	} );
	it( 'scrolls a focused control clear of the measured bar, including wrapped actions', () => {
		revealCatalogueFocus( target );
		expect( window.scrollBy ).toHaveBeenCalledWith( 0, -72 );
		bar.getBoundingClientRect = () => ( { top: 32, bottom: 240 } );
		revealCatalogueFocus( target );
		expect( window.scrollBy ).toHaveBeenLastCalledWith( 0, -200 );
	} );
	it( 'leaves a visible row and the selection actions alone', () => {
		target.getBoundingClientRect = () => ( { top: 130, bottom: 154 } );
		revealCatalogueFocus( target );
		revealCatalogueFocus( document.getElementById( 'action' ) );
		expect( window.scrollBy ).not.toHaveBeenCalled();
	} );
	it( 'leaves controls above a bar which has not become sticky alone', () => {
		bar.getBoundingClientRect = () => ( { top: 160, bottom: 240 } );
		revealCatalogueFocus( target );
		expect( window.scrollBy ).not.toHaveBeenCalled();
	} );
} );
