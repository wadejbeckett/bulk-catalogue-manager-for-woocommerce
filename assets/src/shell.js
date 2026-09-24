/**
 * What the page header shares with the panels (T75): a slot the catalogue
 * draws its Export CSV into, so it sits in the header as the prototype has it,
 * and the header's Bulk change request, which the catalogue answers by
 * opening the bulk change panel on its selection; and the router, so the bulk
 * panel's Undo this change and View in History reach the History panel.
 */
import { createContext } from '@wordpress/element';

export const ShellContext = createContext( {
	exportSlot: null,
	bulkRequested: false,
	consumeBulk: () => {},
	navigate: null,
} );
