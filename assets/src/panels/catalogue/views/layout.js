/**
 * The layout control (SPEC §4.1, T51): table, the default and the layout
 * the design targets, or grid. The layout is the view's `type`, so it is
 * saved and restored with a saved view.
 */
import { __ } from '@wordpress/i18n';

/** Layouts offered, in order; the first is the default. */
export const LAYOUT_TYPES = [ 'table', 'grid' ];

/**
 * The view with another layout; the same view when the layout is unknown.
 *
 * @param {Object} view DataViews view.
 * @param {string} type 'table' or 'grid'.
 * @return {Object} View.
 */
export function withLayout( view, type ) {
	return LAYOUT_TYPES.includes( type ) && view.type !== type
		? { ...view, type }
		: view;
}

/**
 * Two pressed-state buttons in a labelled group, as the prototype's
 * segmented controls.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.view         DataViews view.
 * @param {Function} props.onChangeView View change.
 * @return {Element} Control.
 */
export default function LayoutSwitch( { view, onChangeView } ) {
	const current = LAYOUT_TYPES.includes( view.type )
		? view.type
		: LAYOUT_TYPES[ 0 ];
	const labels = {
		table: __( 'Table', 'bulk-catalogue-manager-for-woocommerce' ),
		grid: __( 'Grid', 'bulk-catalogue-manager-for-woocommerce' ),
	};
	return (
		<div
			className="bcm-views__segmented"
			role="group"
			aria-label={ __(
				'Layout',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		>
			{ LAYOUT_TYPES.map( ( type ) => (
				<button
					key={ type }
					type="button"
					aria-pressed={ current === type }
					onClick={ () => onChangeView( withLayout( view, type ) ) }
				>
					{ labels[ type ] }
				</button>
			) ) }
		</div>
	);
}
