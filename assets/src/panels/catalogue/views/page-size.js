/**
 * Rows per page (ticket 19). DataViews' own control is disabled until a
 * column is sorted; the catalogue's default order is the server's, so this
 * control offers the sizes whatever the sort, and never adds one.
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { PER_PAGE_SIZES } from '../defaults';

/**
 * The control.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.view         DataViews view.
 * @param {Function} props.onChangeView View change.
 * @return {Element} Control.
 */
export default function PageSizeControl( { view, onChangeView } ) {
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			className="bulkcat-views__page-size"
			label={ __(
				'Rows per page',
				'bulk-catalogue-manager-for-woocommerce'
			) }
			value={ String( view.perPage ) }
			options={ PER_PAGE_SIZES.map( ( size ) => ( {
				label: String( size ),
				value: String( size ),
			} ) ) }
			onChange={ ( value ) =>
				onChangeView( { ...view, page: 1, perPage: Number( value ) } )
			}
		/>
	);
}
