/**
 * The Catalogue panel (SPEC §4.1): a DataViews table over GET /products,
 * paged, sorted and searched on the server, its fields built from GET
 * /fields. The view lives in React state; each change re-queries.
 *
 * Structure: use-catalogue.js (data hooks), fields.js (registry to DataViews),
 * query.js (view to route arguments), cells.js (renderers by value type),
 * states.js (loading, empty, filtered-empty, error), actions.js (row menu),
 * table.js (the table), defaults.js (the only module naming field keys).
 */
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { rowActions } from './actions';
import { descriptorsOf, defaultView, fieldsFromRegistry } from './fields';
import { clearFilters, isFiltered } from './query';
import {
	activeFilters,
	ErrorNotice,
	LoadingRows,
	NoMatches,
	NoProducts,
} from './states';
import CatalogueTable from './table';
import { useCatalogue, useRegistry, useTermNames } from './use-catalogue';
import './style.scss';

export { fieldsFromRegistry, defaultView } from './fields';
export { toQuery } from './query';
export { useCatalogue, useRegistry } from './use-catalogue';
export { default as CatalogueTable } from './table';

/**
 * The grid once the registry has arrived.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.registry      GET /fields answer.
 * @param {Object}   props.settings      Localised settings.
 * @param {Object}   props.initialView   View overrides to open with (a saved view, tests).
 * @param {Function} props.onEditDetails Open the detail panel (T47).
 * @return {Element} Grid.
 */
export function CatalogueGrid( {
	registry,
	settings,
	initialView,
	onEditDetails,
} ) {
	const descriptors = useMemo(
		() => descriptorsOf( registry ),
		[ registry ]
	);
	const fields = useMemo(
		() => fieldsFromRegistry( registry ),
		[ registry ]
	);
	const [ view, setView ] = useState( () => ( {
		...defaultView( registry, settings ),
		...initialView,
	} ) );
	const catalogue = useCatalogue( view, descriptors );
	const sources = useMemo(
		() =>
			descriptors
				.filter(
					( field ) =>
						field.source &&
						( view.fields || [] ).includes( field.key )
				)
				.map( ( field ) => field.source ),
		[ descriptors, view.fields ]
	);
	const termNames = useTermNames( sources );
	const actions = useMemo(
		() => rowActions( { onEditDetails } ),
		[ onEditDetails ]
	);
	const clear = useCallback(
		() => setView( ( current ) => clearFilters( current ) ),
		[]
	);

	const filtered = isFiltered( view );
	if (
		catalogue.hasLoaded &&
		! catalogue.isLoading &&
		! catalogue.error &&
		! filtered &&
		catalogue.pagination.totalItems === 0
	) {
		return <NoProducts />;
	}

	return (
		<>
			{ catalogue.error && (
				<ErrorNotice
					message={ __(
						'The products could not be loaded.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					error={ catalogue.error }
					onRetry={ catalogue.refetch }
				/>
			) }
			<CatalogueTable
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				items={ catalogue.items }
				pagination={ catalogue.pagination }
				isLoading={ catalogue.isLoading }
				hasLoaded={ catalogue.hasLoaded || !! catalogue.error }
				actions={ actions }
				termNames={ termNames }
				pending={ catalogue.pending }
				empty={
					catalogue.error ? null : (
						<NoMatches
							filters={ activeFilters( view, descriptors ) }
							onClear={ clear }
						/>
					)
				}
			/>
		</>
	);
}

/**
 * The panel: loads the registry, then the grid.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.settings      Localised settings.
 * @param {Object}   props.initialView   View overrides.
 * @param {Function} props.onEditDetails Open the detail panel (T47).
 * @return {Element} Panel.
 */
export default function Catalogue( { settings, initialView, onEditDetails } ) {
	const { registry, error, retry } = useRegistry();
	return (
		<div className="bcm-catalogue">
			{ error && (
				<ErrorNotice
					message={ __(
						'The list of product fields could not be loaded, so the products cannot be shown.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					error={ error }
					onRetry={ retry }
				/>
			) }
			{ registry && (
				<CatalogueGrid
					registry={ registry }
					settings={ settings }
					initialView={ initialView }
					onEditDetails={ onEditDetails }
				/>
			) }
			{ ! registry && ! error && <LoadingRows /> }
		</div>
	);
}
