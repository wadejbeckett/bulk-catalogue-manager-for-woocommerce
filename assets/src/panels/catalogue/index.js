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
 *
 * Search and filters (T41) live in toolbar/: the registry's filter
 * definitions, viewToQuery() and the chips. The selection (T42) lives in
 * selection/: ids kept across pages, select all matching through POST
 * /selection, and the sticky selection bar that opens the bulk panel.
 */
import { Button } from '@wordpress/components';
import {
	createPortal,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { request } from '../../api';
import { ShellContext } from '../../shell';
import BulkChangePanel from '../bulk';
import { FINISHED, useBatchProgress } from '../bulk/hooks';
import { useProgressAnnouncer } from '../bulk/announce';
import { doneSentence } from '../bulk/progress';
import { announce } from '../categories/announce';
import { CategoryPanel, useCategoryTree } from '../categories/index';
import { categorySentence } from '../categories/tree-model';
import { BatchProgress, StalledNotice } from '../history';
import { useSettings } from '../settings/store';
import { rowActions } from './actions';
import { descriptorsOf, defaultView, fieldsFromRegistry } from './fields';
import { categoryArgs, clearFilters, isFiltered } from './query';
import { ErrorNotice, LoadingRows, NoMatches, NoProducts } from './states';
import { useCatalogue, useRegistry, useTermNames } from './use-catalogue';
// T41, T42: search, filters, chips and the selection.
import { CATEGORY_FILTER } from './defaults';
import {
	FilterChips,
	filterArgs,
	filterChips,
	filterSources,
	selectionQuery,
	withFilters,
	withoutFilter,
} from './toolbar';
import {
	SelectionBar,
	addCategoryOperation,
	panelSelection,
	useSelection,
	visibilityOperation,
} from './selection';
// End T41, T42.
// T51–T53: saved views, the layout switch, the cards and the export.
import {
	CatalogueLayout,
	ExportControl,
	LayoutSwitch,
	SavedViewsBar,
} from './views';
import { switchFieldId } from './views/cards';
import { MODES, modeOf } from './variations';
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
 * once the panel is closed (SPEC §4.1 Change in progress): BatchProgress, or
 * the stalled notice in its place, with Cancel and View in History (T75).
 * The rows are read again when it finishes.
 *
 * @param {Object}   props               Props.
 * @param {number}   props.batchId       The batch.
 * @param {Function} props.onFinished    Called once with the finished report.
 * @param {Function} props.onViewHistory Open History at this batch, or null.
 * @return {Element|null} Progress.
 */
export function AppliedProgress( {
	batchId,
	onFinished,
	onViewHistory = null,
} ) {
	const [ cancelling, setCancelling ] = useState( false );
	const [ refused, setRefused ] = useState( '' );
	const { report } = useBatchProgress( batchId );
	const finished = FINISHED.includes( report?.status );
	const tell = useRef( onFinished );
	tell.current = onFinished;
	// T75: progress is said at milestones and the result once (SPEC §14).
	useProgressAnnouncer(
		Number( report?.done_items ) || 0,
		Number( report?.total_items ) || 0,
		finished
	);
	const told = useRef( false );
	useEffect( () => {
		if ( finished && ! told.current ) {
			told.current = true;
			announce( doneSentence( report ), 'polite' );
			tell.current( report );
		}
	}, [ finished, report ] );
	if ( ! report || finished ) {
		return null;
	}
	const cancel = () => {
		setCancelling( true );
		setRefused( '' );
		request( `batches/${ Number( batchId ) }/cancel`, {
			method: 'POST',
		} ).catch( ( error ) => {
			setCancelling( false );
			const text =
				/^bulkcat_/.test( error?.code || '' ) && error.message
					? error.message
					: __(
							'The change could not be cancelled. Try again in a moment.',
							'bulk-catalogue-manager-for-woocommerce'
						);
			setRefused( text );
			announce( text, 'assertive' );
		} );
	};
	return (
		<div
			className="bulkcat-catalogue__progress"
			role="group"
			aria-label={ __(
				'Change in progress',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		>
			<BatchProgress report={ report } />
			<span className="bulkcat-catalogue__progress-acts">
				<Button
					variant="secondary"
					size="compact"
					onClick={ cancel }
					disabled={ cancelling }
					accessibleWhenDisabled
				>
					{ cancelling
						? __(
								'Cancelling…',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Cancel',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</Button>
				{ onViewHistory && (
					<Button
						variant="link"
						onClick={ () => onViewHistory( batchId ) }
					>
						{ __(
							'View in History',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
			</span>
			{ refused && (
				<p className="bulkcat-catalogue__progress-error" role="alert">
					{ refused }
				</p>
			) }
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
	// The screen's starting view, under a saved view's keys (T52).
	const baseView = useMemo(
		() => defaultView( registry, settings ),
		// A settings save must not reset the screen's view.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ registry ]
	);
	const [ view, setView ] = useState( () => ( {
		...baseView,
		...initialView,
	} ) );
	const [ category, setCategory ] = useState( null );
	const [ sentence, setSentence ] = useState( '' );
	// T41, T42: the bulk panel opens on the selection, with any quick action's step.
	const [ bulk, setBulk ] = useState( null );
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
				.map( ( field ) => field.source )
				// T41: the filters name their terms too.
				.concat( filterSources( descriptors ) ),
		[ descriptors, view.fields ]
	);
	const termNames = useTermNames( [ ...new Set( sources ) ] );
	const actions = useMemo(
		() =>
			rowActions( {
				onEditDetails,
				onMakeChange: ( ids ) =>
					setBulk( { selection: { kind: 'ids', ids } } ),
			} ),
		[ onEditDetails ]
	);
	// T41, T42: filter fields, chips, the selection and its quick actions.
	const filterFields = useMemo(
		() => withFilters( fields, descriptors, termNames ),
		[ fields, descriptors, termNames ]
	);
	const chips = useMemo(
		() => filterChips( view, descriptors, termNames ),
		[ view, descriptors, termNames ]
	);
	const conditions = useMemo(
		() => selectionQuery( view, descriptors, extra ),
		[ view, descriptors, extra ]
	);
	const selection = useSelection( { conditions, items: catalogue.items } );
	const showStep = useMemo(
		() => visibilityOperation( registry, settings, true ),
		[ registry, settings ]
	);
	const hideStep = useMemo(
		() => visibilityOperation( registry, settings, false ),
		[ registry, settings ]
	);
	const openBulk = ( steps = null ) =>
		setBulk( {
			selection: panelSelection( selection, chips ),
			initialOperations: steps,
		} );
	// T65: the selection dropped on a category opens the panel with Add
	// categories for it; nothing is written before preview and apply.
	const hasSelection = selection.count > 0 || !! selection.matching;
	const dropSelection = ( categoryId ) => {
		const step = addCategoryOperation( registry, settings, categoryId );
		if ( step && hasSelection ) {
			openBulk( [ step ] );
		}
	};
	// T75: the header's Bulk change opens the panel on the selection, or,
	// with nothing selected, on every product matching the view once the
	// server has resolved them.
	const shell = useContext( ShellContext );
	const [ bulkWanted, setBulkWanted ] = useState( false );
	const { bulkRequested, consumeBulk } = shell;
	useEffect( () => {
		if ( ! bulkRequested ) {
			return;
		}
		consumeBulk();
		if ( hasSelection ) {
			openBulk();
		} else {
			setBulkWanted( true );
			selection.selectAllMatching();
		}
		// openBulk reads the selection at the time of the request.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bulkRequested ] );
	const matchingStatus = selection.matching?.status;
	useEffect( () => {
		if ( ! bulkWanted ) {
			return;
		}
		if ( matchingStatus === 'ready' ) {
			setBulkWanted( false );
			openBulk();
		} else if ( matchingStatus !== 'loading' ) {
			setBulkWanted( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bulkWanted, matchingStatus ] );
	const viewCategory = !! filterArgs( view.filters, descriptors )[
		CATEGORY_FILTER
	];
	useEffect( () => {
		// A category filter chosen in the filters replaces the panel's choice.
		if ( viewCategory ) {
			setCategory( null );
			setSentence( '' );
		}
	}, [ viewCategory ] );
	// End T41, T42.
	const clear = useCallback( () => {
		setView( ( current ) => clearFilters( current ) );
		setCategory( null );
		setSentence( '' );
	}, [] );
	const chooseCategory = useCallback( ( value, words ) => {
		setCategory( value );
		setSentence( value ? words : '' );
		setView( ( current ) => ( {
			...withoutFilter( current, CATEGORY_FILTER ), // T41
			page: 1,
		} ) );
	}, [] );
	// T52: a saved or built-in view sets the view and the category panel.
	const { byId } = tree;
	const applyView = useCallback(
		( next, value ) => {
			setView( next );
			setCategory( value || null );
			setSentence( value ? categorySentence( value, byId ) : '' );
		},
		[ byId ]
	);
	// T51: the cards' Visible on site slot draws the switch field's own cell.
	const renderSwitch = useMemo( () => {
		const slot = switchFieldId( filterFields );
		const field = filterFields.find( ( each ) => each.id === slot );
		return field ? ( item ) => field.render( { item, field } ) : undefined;
	}, [ filterFields ] );
	const variationsMode = modeOf( view ) === MODES.variations;
	const { refetch } = catalogue;
	// T75: the rows a running change covers are pending and read-only until
	// it finishes (SPEC §4.1): the ticked ids, or the page for a filter.
	const { setPending, items: pageItems } = catalogue;
	const covered = useRef( [] );
	const onApplied = useCallback(
		( id ) => {
			const scope = bulk?.selection;
			covered.current =
				scope?.kind === 'ids'
					? scope.ids.map( Number )
					: pageItems.map( ( item ) => item.id );
			setPending( covered.current, true );
			setApplied( id );
		},
		[ bulk, pageItems, setPending ]
	);
	const finished = useCallback( () => {
		setPending( covered.current, false );
		covered.current = [];
		setApplied( null );
		refetch();
	}, [ refetch, setPending ] );

	const exportControl = (
		<ExportControl
			query={ catalogue.query }
			view={ view }
			registry={ registry }
		/>
	);
	const filtered = isFiltered( view ) || !! category;
	if (
		catalogue.hasLoaded &&
		! catalogue.isLoading &&
		! catalogue.error &&
		! filtered &&
		! variationsMode &&
		catalogue.pagination.totalItems === 0
	) {
		return <NoProducts />;
	}

	return (
		<div className="bulkcat-catalogue__body">
			<CategoryPanel
				value={ category }
				onChange={ chooseCategory }
				query={ treeQuery }
				tree={ tree }
				settings={ settings }
				onDropSelection={ hasSelection ? dropSelection : undefined }
			/>
			<div className="bulkcat-catalogue__main">
				{ /* T51–T53; T75: one row, as in the prototype. */ }
				<div className="bulkcat-catalogue__top">
					<SavedViewsBar
						registry={ registry }
						view={ view }
						category={ category }
						baseView={ baseView }
						onApply={ applyView }
					/>
					<div className="bulkcat-catalogue__toolbar">
						<LayoutSwitch view={ view } onChangeView={ setView } />
						{ shell.exportSlot
							? createPortal( exportControl, shell.exportSlot )
							: exportControl }
					</div>
				</div>
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
					<p className="bulkcat-catalogue__chip">
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
				{ /* T41, T42 */ }
				<FilterChips
					chips={ chips }
					onChange={ setView }
					onClearAll={ clear }
				/>
				<SelectionBar
					selection={ selection }
					pageCount={ catalogue.items.length }
					total={ catalogue.pagination.totalItems }
					filtered={ filtered }
					onMakeChange={ () => openBulk() }
					onShow={ showStep ? () => openBulk( [ showStep ] ) : null }
					onHide={ hideStep ? () => openBulk( [ hideStep ] ) : null }
					draggable
				/>
				{ applied && ! bulk && (
					<AppliedProgress
						batchId={ applied }
						onFinished={ finished }
						onViewHistory={
							shell.navigate
								? ( id ) =>
										shell.navigate( 'history', {
											batch: id,
										} )
								: null
						}
					/>
				) }
				<CatalogueLayout
					renderSwitch={ renderSwitch }
					fields={ filterFields }
					view={ view }
					onChangeView={ setView }
					items={ catalogue.items }
					pagination={ catalogue.pagination }
					isLoading={ catalogue.isLoading }
					hasLoaded={ catalogue.hasLoaded || !! catalogue.error }
					actions={ actions }
					termNames={ termNames }
					pending={ catalogue.pending }
					selection={ selection.tableSelection }
					onChangeSelection={ selection.onChangeSelection }
					empty={
						catalogue.error ? null : (
							<NoMatches
								variations={ variationsMode }
								filters={ [
									sentence,
									...chips.map( ( chip ) => chip.sentence ),
								].filter( Boolean ) }
								onClear={ clear }
							/>
						)
					}
				/>
			</div>
			{ bulk && (
				<BulkChangePanel
					selection={ bulk.selection }
					initialOperations={ bulk.initialOperations }
					registry={ registry }
					settings={ settings }
					categories={ tree.nodes }
					onClose={ () => setBulk( null ) }
					onApplied={ onApplied }
					onUndo={
						shell.navigate
							? ( id ) =>
									shell.navigate( 'history', {
										batch: id,
										undo: 1,
									} )
							: null
					}
					onViewHistory={
						shell.navigate
							? ( id ) =>
									shell.navigate( 'history', { batch: id } )
							: null
					}
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
		<div className="bulkcat-catalogue">
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
