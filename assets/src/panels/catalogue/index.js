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
 * definitions, viewToQuery() and the chips. The selection (T42, T84) lives
 * in selection/: the screen's exact selection session, kept across searches,
 * filters, pages and panels, Select all matching adding the view's matches,
 * Review selected products, and the sticky selection bar that opens the bulk
 * panel on it. Only Clear selection, Clear search, a removal or a change
 * that finished in full changes what is selected.
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
import { FINISHED, resumeApply, useBatchProgress } from '../bulk/hooks';
import { useProgressAnnouncer } from '../bulk/announce';
import { doneSentence } from '../bulk/progress';
import { announce } from '../categories/announce';
import { CategoryPanel, useCategoryTree } from '../categories/index';
import { categorySentence } from '../categories/tree-model';
import { BatchProgress, StalledNotice } from '../history';
import { useSettings } from '../settings/store';
import { rowActions } from './actions';
import { useNativeReturn } from './use-native-return';
import { descriptorsOf, defaultView, fieldsFromRegistry } from './fields';
import { onCatalogueFocus } from './focus-visibility';
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
	SelectedProducts,
	SelectionBar,
	addCategoryOperation,
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
// Ticket 19: personal display choices and rows per page.
import { applyDisplay, displayOf, sameDisplay } from './views/display-state';
import PageSizeControl from './views/page-size';
import {
	useDisplayPreferences,
	useRememberDisplay,
} from './views/use-display-preferences';
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
 * @param {boolean}  props.replayed      The apply answered a change already accepted (T84).
 * @return {Element|null} Progress.
 */
export function AppliedProgress( {
	batchId,
	onFinished,
	onViewHistory = null,
	replayed = false,
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
			{ replayed && (
				<p className="bulkcat-catalogue__progress-note">
					{ __(
						'This change had already been accepted, so it was not sent again. Its progress is shown here.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
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
 * @param {Object}   props.personal      The user's stored display choices, or null (ticket 19).
 * @param {boolean}  props.personalRead  Whether the stored choices have been read.
 * @param {Function} props.onEditDetails Open the detail panel (T47).
 * @return {Element} Grid.
 */
export function CatalogueGrid( {
	registry,
	settings: localised,
	initialView,
	personal = null,
	personalRead = false,
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
	// Ticket 19: the user's own display choices over the starting view.
	const known = useMemo(
		() => descriptors.map( ( field ) => field.key ),
		[ descriptors ]
	);
	const [ view, setView ] = useState( () => ( {
		...applyDisplay( baseView, personal, known ),
		...initialView,
	} ) );
	// The display the screen set itself (opening, default view), which the
	// stored choices may replace when they arrive; a user's own change is kept.
	const ownDisplay = useRef( displayOf( view ) );
	useEffect( () => {
		if ( ! personal ) {
			return;
		}
		setView( ( current ) =>
			sameDisplay( displayOf( current ), ownDisplay.current )
				? applyDisplay( current, personal, known )
				: current
		);
	}, [ personal, known ] );
	const displayMemory = useRememberDisplay( view, personalRead, personal );
	const [ category, setCategory ] = useState( null );
	const [ sentence, setSentence ] = useState( '' );
	// T41, T42: the bulk panel opens on the selection, with any quick action's step.
	const [ bulk, setBulk ] = useState( null );
	const [ applied, setApplied ] = useState( null );
	// T84: the rows drawn, for inspecting which are selected; the review.
	const [ drawn, setDrawn ] = useState( [] );
	const onDrawn = useCallback(
		( ids ) =>
			setDrawn( ( was ) =>
				was.length === ids.length &&
				was.every( ( id, at ) => id === ids[ at ] )
					? was
					: ids
			),
		[]
	);
	const [ reviewing, setReviewing ] = useState( false );
	const extra = useMemo( () => categoryArgs( category ), [ category ] );
	const catalogue = useCatalogue( view, descriptors, extra, setView );
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
					setBulk( {
						selection: {
							kind: 'ids',
							ids: [ ...ids ]
								.map( Number )
								.sort( ( a, b ) => a - b ),
						},
					} ),
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
	const selection = useSelection( {
		conditions,
		items: catalogue.items,
		drawn,
	} );
	const showStep = useMemo(
		() => visibilityOperation( registry, settings, true ),
		[ registry, settings ]
	);
	const hideStep = useMemo(
		() => visibilityOperation( registry, settings, false ),
		[ registry, settings ]
	);
	// T84: the panel reads the live selection, so a renewed token, a tick
	// made while it is open or an unsettled change reaches it at once.
	const openBulk = ( steps = null ) =>
		setBulk( { live: true, initialOperations: steps } );
	// T65: the selection dropped on a category opens the panel with Add
	// categories for it; nothing is written before preview and apply.
	const hasSelection = selection.hasSelection;
	const dropSelection = ( categoryId ) => {
		const step = addCategoryOperation( registry, settings, categoryId );
		if ( step && hasSelection ) {
			openBulk( [ step ] );
		}
	};
	// T75: the header's Bulk change opens the panel on the selection, or,
	// with nothing selected, on every product matching the view once the
	// server has captured them (T84: added to the selection, like Select
	// all matching).
	const shell = useContext( ShellContext );
	const { bulkRequested, consumeBulk } = shell;
	const mounted = useRef( true );
	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);
	useEffect( () => {
		if ( ! bulkRequested ) {
			return;
		}
		consumeBulk();
		if ( hasSelection ) {
			openBulk();
		} else {
			selection.selectAllMatching().then( ( saved ) => {
				if ( saved && mounted.current ) {
					openBulk();
				}
			} );
		}
		// openBulk reads the selection at the time of the request.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bulkRequested ] );
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
	// T84: Clear search is deliberate: the search and the selection go.
	// T85: its notice offers Restore selection, which brings the selection
	// back while the server still keeps it. The search is not put back: the
	// search box's own 250 ms debounce would write the cleared text over it
	// when Restore follows Clear quickly.
	const { clearWithSearch, restore } = selection;
	const clearSearch = useCallback( () => {
		setView( ( current ) => ( { ...current, search: '', page: 1 } ) );
		clearWithSearch();
	}, [ clearWithSearch ] );
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
		( next, value, how = {} ) => {
			// Ticket 19: the default view opening the screen keeps the user's own
			// display choices; a view picked deliberately brings its own.
			const shown = how.opening
				? applyDisplay( next, personal, known )
				: next;
			if ( how.opening ) {
				ownDisplay.current = displayOf( shown );
			}
			setView( shown );
			setCategory( value || null );
			setSentence( value ? categorySentence( value, byId ) : '' );
		},
		[ byId, personal, known ]
	);
	// T51: the cards' Shown in shop and search slot draws the switch field's own cell.
	const renderSwitch = useMemo( () => {
		const slot = switchFieldId( filterFields );
		const field = filterFields.find( ( each ) => each.id === slot );
		return field ? ( item ) => field.render( { item, field } ) : undefined;
	}, [ filterFields ] );
	const variationsMode = modeOf( view ) === MODES.variations;
	const { refetch } = catalogue;
	// T75: the rows a running change covers are pending and read-only until
	// it finishes (SPEC §4.1): the ids, or the selected rows on screen.
	const { setPending } = catalogue;
	const { session, tableSelection } = selection;
	const nativeReturn = useNativeReturn( {
		reconcile: catalogue.reconcile,
		session,
		drawn,
	} );
	const covered = useRef( [] );
	const done = useRef( new Set() );
	const onApplied = useCallback(
		( id, answer = {} ) => {
			const scope =
				'selection' in answer ? answer.selection : bulk?.selection;
			covered.current =
				scope?.kind === 'ids'
					? scope.ids.map( Number )
					: ( scope ? tableSelection : [] ).map( Number );
			setPending( covered.current, true );
			setApplied( { id, replayed: !! answer.replayed } );
			// T84: the snapshot applied is kept until the change finishes.
			if ( scope?.kind === 'snapshot' && scope.key ) {
				session.submit( id, scope.key );
			}
		},
		[ bulk, tableSelection, setPending, session ]
	);
	// T85: after a reload, an Apply whose answer was never known is resolved
	// with its own grant (never a new one). When it had gone through, its
	// progress is shown as for any Apply and the snapshot it was applied to
	// is the submitted one, so a change that finishes in full still clears
	// what it applied; otherwise the grant stays for the same change.
	useEffect( () => {
		let live = true;
		resumeApply().then( ( earlier ) => {
			if ( live && earlier?.batch_id ) {
				onApplied( earlier.batch_id, {
					selection: earlier.selectionKey
						? { kind: 'snapshot', key: earlier.selectionKey }
						: null,
					replayed: true,
				} );
			}
		} );
		return () => {
			live = false;
		};
		// Once, when the grid first mounts after a load.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	// Once per change, whichever of the panel and the banner sees it end:
	// full success clears what was applied from the selection (T84).
	const finished = useCallback(
		( report ) => {
			const id = Number( report?.batch_id ) || applied?.id;
			if ( done.current.has( id ) ) {
				return;
			}
			done.current.add( id );
			setPending( covered.current, false );
			covered.current = [];
			setApplied( ( was ) => ( was?.id === id ? null : was ) );
			refetch();
			session.finish( id, report );
		},
		[ applied, refetch, setPending, session ]
	);
	const toHistory = shell.navigate
		? ( id, undo = false ) =>
				shell.navigate(
					'history',
					undo ? { batch: id, undo: 1 } : { batch: id }
				)
		: null;

	const exportControl = (
		<ExportControl
			query={ catalogue.query }
			view={ view }
			registry={ registry }
			selection={ selection.panel }
			onRenewSelection={ () => session.renewAll() }
		/>
	);
	const filtered = isFiltered( view ) || !! category;
	if (
		catalogue.hasLoaded &&
		! catalogue.isLoading &&
		! catalogue.error &&
		! nativeReturn.error &&
		! filtered &&
		! variationsMode &&
		catalogue.pagination.totalItems === 0
	) {
		return <NoProducts />;
	}

	return (
		<div
			className={
				'bulkcat-catalogue__body' + ( bulk ? ' has-slide-over' : '' )
			}
		>
			<CategoryPanel
				compact={ !! bulk }
				value={ category }
				onChange={ chooseCategory }
				query={ treeQuery }
				tree={ tree }
				settings={ settings }
				onDropSelection={ hasSelection ? dropSelection : undefined }
			/>
			<div
				className="bulkcat-catalogue__main"
				onFocusCapture={ onCatalogueFocus }
			>
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
						<PageSizeControl
							view={ view }
							onChangeView={ setView }
						/>
						{ shell.exportSlot
							? createPortal( exportControl, shell.exportSlot )
							: exportControl }
					</div>
				</div>
				{ displayMemory.error && (
					<ErrorNotice
						message={ __(
							'Your display choices could not be saved, so they may not be kept next time.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						error={ displayMemory.error }
						onRetry={ displayMemory.retry }
					/>
				) }
				{ nativeReturn.error && (
					<ErrorNotice
						message={ __(
							'Selected products could not be refreshed. Your selection has been kept.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						error={ nativeReturn.error }
						onRetry={ nativeReturn.retry }
					/>
				) }
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
				{ /* T41, T42, T84 */ }
				<FilterChips
					chips={ chips }
					onChange={ setView }
					onClearAll={ clear }
					onClearSearch={ clearSearch }
					selectionCount={ selection.count }
				/>
				<SelectionBar
					selection={ selection }
					pageCount={ catalogue.items.length }
					total={ catalogue.pagination.totalItems }
					filtered={ filtered }
					mode={ modeOf( view ) }
					onMakeChange={ () => openBulk() }
					onReview={ () => setReviewing( true ) }
					onShow={ showStep ? () => openBulk( [ showStep ] ) : null }
					onHide={ hideStep ? () => openBulk( [ hideStep ] ) : null }
					onUndo={
						toHistory ? ( id ) => toHistory( id, true ) : null
					}
					onViewHistory={ toHistory }
					onRestore={ restore }
					draggable
				/>
				{ applied && ! bulk && (
					<AppliedProgress
						batchId={ applied.id }
						replayed={ applied.replayed }
						onFinished={ finished }
						onViewHistory={ toHistory }
					/>
				) }
				<CatalogueLayout
					settings={ settings }
					onCommitted={ catalogue.reconcile }
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
					onDrawn={ onDrawn }
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
					selection={ bulk.live ? selection.panel : bulk.selection }
					initialOperations={ bulk.initialOperations }
					registry={ registry }
					settings={ settings }
					categories={ tree.nodes }
					onClose={ () => setBulk( null ) }
					onApplied={ onApplied }
					onFinished={ finished }
					onReview={
						bulk.live && hasSelection
							? () => setReviewing( true )
							: null
					}
					onRenewSelection={
						bulk.live ? () => session.renewAll() : null
					}
					onUndo={
						toHistory ? ( id ) => toHistory( id, true ) : null
					}
					onViewHistory={ toHistory }
					renderStalled={ ( report ) => (
						<StalledNotice report={ report } />
					) }
				/>
			) }
			{ reviewing && (
				<SelectedProducts
					selection={ selection }
					onClose={ () => setReviewing( false ) }
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
	const preferences = useDisplayPreferences();
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
					personal={ preferences.display }
					personalRead={ preferences.hasLoaded }
					onEditDetails={ onEditDetails }
				/>
			) }
			{ ! registry && ! error && <LoadingRows /> }
		</div>
	);
}
