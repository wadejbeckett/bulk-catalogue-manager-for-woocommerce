/**
 * The Catalogue panel (SPEC §4.1): a DataViews table over GET /products,
 * paged, sorted and searched on the server, its fields built from GET
 * /fields. The view lives in React state; each change re-queries.
 *
 * Structure: use-catalogue.js (data hooks), fields.js (registry to DataViews),
 * query.js (view to route arguments), cells.js (renderers by value type),
 * states.js (loading, empty, filtered-empty, error), actions.js (row menu),
 * table.js (the table), defaults.js (the only module naming field keys).
 *
 * Around the table: the category panel on its left, whose value filters the
 * rows (T61, T62); "Make a change to these" on the selection, which opens the
 * bulk change panel (T48–T50); and the progress of an applied change once
 * that panel is closed (T44).
 */
import { Button } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import BulkChangePanel from '../bulk';
import { FINISHED, useBatchProgress } from '../bulk/hooks';
import { CategoryPanel, useCategoryTree } from '../categories/index';
import { BatchProgress, StalledNotice } from '../history';
import { useSettings } from '../settings/store';
import { rowActions } from './actions';
import { descriptorsOf, defaultView, fieldsFromRegistry } from './fields';
import { categoryArgs, clearFilters, isFiltered } from './query';
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
 * The localised settings with the current values from useSettings(), so a
 * save on the Settings tab reaches the catalogue without a reload.
 *
 * @param {Object} settings Localised settings.
 * @return {Object} The same shape, `settings` brought up to date.
 */
function useCurrentSettings( settings ) {
	const values = useSettings();
	return useMemo(
		() => ( {
			...settings,
			settings: { ...( settings?.settings || {} ), ...values },
		} ),
		[ settings, values ]
	);
}

/**
 * The progress of a change applied from the bulk panel, shown above the table
 * once the panel is closed: BatchProgress, or the stalled notice in its place.
 * The rows are read again when it finishes.
 *
 * @param {Object}   props            Props.
 * @param {number}   props.batchId    The batch.
 * @param {Function} props.onFinished Called once with the finished report.
 * @return {Element|null} Progress.
 */
export function AppliedProgress( { batchId, onFinished } ) {
	const { report } = useBatchProgress( batchId );
	const finished = FINISHED.includes( report?.status );
	const tell = useRef( onFinished );
	tell.current = onFinished;
	useEffect( () => {
		if ( finished ) {
			tell.current( report );
		}
	}, [ finished, report ] );
	if ( ! report || finished ) {
		return null;
	}
	return (
		<div className="bcm-catalogue__progress" role="status">
			<BatchProgress report={ report } />
		</div>
	);
}

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
	settings: localised,
	initialView,
	onEditDetails,
} ) {
	const settings = useCurrentSettings( localised );
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
	const [ category, setCategory ] = useState( null );
	const [ sentence, setSentence ] = useState( '' );
	const [ selection, setSelection ] = useState( [] );
	const [ bulkIds, setBulkIds ] = useState( null );
	const [ applied, setApplied ] = useState( null );
	const extra = useMemo( () => categoryArgs( category ), [ category ] );
	const catalogue = useCatalogue( view, descriptors, extra );
	const treeQuery = useMemo(
		() => ( view.search ? { search: view.search } : {} ),
		[ view.search ]
	);
	const tree = useCategoryTree( treeQuery );
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
		() => rowActions( { onEditDetails, onMakeChange: setBulkIds } ),
		[ onEditDetails ]
	);
	const clear = useCallback( () => {
		setView( ( current ) => clearFilters( current ) );
		setCategory( null );
		setSentence( '' );
	}, [] );
	const chooseCategory = useCallback( ( value, words ) => {
		setCategory( value );
		setSentence( value ? words : '' );
		setView( ( current ) => ( { ...current, page: 1 } ) );
	}, [] );
	const { refetch } = catalogue;
	const finished = useCallback( () => {
		setApplied( null );
		refetch();
	}, [ refetch ] );

	const filtered = isFiltered( view ) || !! category;
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
		<div className="bcm-catalogue__body">
			<CategoryPanel
				value={ category }
				onChange={ chooseCategory }
				query={ treeQuery }
				tree={ tree }
				settings={ settings }
			/>
			<div className="bcm-catalogue__main">
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
				{ sentence && (
					<p className="bcm-catalogue__chip">
						<span>{ sentence }</span>
						<Button
							variant="link"
							onClick={ () => chooseCategory( null, '' ) }
						>
							{ __(
								'Show all products',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					</p>
				) }
				{ applied && ! bulkIds && (
					<AppliedProgress
						batchId={ applied }
						onFinished={ finished }
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
					selection={ selection }
					onChangeSelection={ setSelection }
					empty={
						catalogue.error ? null : (
							<NoMatches
								filters={ activeFilters( view, descriptors ) }
								onClear={ clear }
							/>
						)
					}
				/>
			</div>
			{ bulkIds && (
				<BulkChangePanel
					selection={ { kind: 'ids', ids: bulkIds } }
					registry={ registry }
					settings={ settings }
					categories={ tree.nodes }
					onClose={ () => setBulkIds( null ) }
					onApplied={ setApplied }
					renderStalled={ ( report ) => (
						<StalledNotice report={ report } />
					) }
				/>
			) }
		</div>
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
