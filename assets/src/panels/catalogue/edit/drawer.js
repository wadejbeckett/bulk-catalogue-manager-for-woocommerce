/**
 * The product detail panel (SPEC §4.1 Detail panel, §5.3, §14; T47): a
 * DataForm drawer holding every version 1 field of one product — the
 * four-way catalogue visibility and the full status included — built from
 * the registry alone. A field that does not apply to the product is shown
 * with the registry's reason instead of a control. Below the form: the
 * product's variations, read-only, and its most recent recorded change with
 * a link to its history.
 *
 * The drawer traps focus, closes on Escape and returns focus to what opened
 * it (scope.js); closing with unsaved changes asks first. Save checks every
 * changed field against the registry's rules, shows a refusal beside its own
 * field and writes nothing, otherwise writes only the changed fields in one
 * PATCH — a batch of one, journalled and undoable — and the row updates from
 * the answer.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { panelHref } from '../../../router';
import { renderCell, formatDate, textOf } from '../cells';
import { ROW, TERM_ROUTES, VISIBILITY } from '../defaults';
import { rolesOf } from '../fields';
import { flattenTree } from '../use-catalogue';
import { request } from '../../../api';
import { loadProduct, loadRecentChange, loadVariations } from './requests';
import {
	allowedChoices,
	comparable,
	failureText,
	fieldErrors,
	toRequestValue,
	unavailableReason,
	validate,
} from './rules';
import { productName } from './switch';

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * The fields the panel edits: every editable registry field except the
 * switch, which the four-way visibility field states exactly.
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Array<Object>} Descriptors.
 */
export function panelDescriptors( descriptors ) {
	return descriptors.filter(
		( field ) => ! field.readOnly && field.key !== VISIBILITY.field
	);
}

/**
 * The DataForm field for a descriptor on one product.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} item       The product's row.
 * @param {Object} termNames  source => Map( id => name ).
 * @return {Object} DataForm field.
 */
export function formField( descriptor, item, termNames ) {
	const field = {
		...descriptor.dataviews,
		id: descriptor.key,
		label: descriptor.label,
		enableHiding: false,
	};
	delete field.filterBy;
	const reason = unavailableReason( descriptor, item );
	const show = ( { item: row } ) =>
		renderCell( { item: row, descriptor, role: '' } );
	if ( reason ) {
		return { ...field, readOnly: true, description: reason, render: show };
	}
	if ( descriptor.type === 'enum' ) {
		field.elements = allowedChoices( descriptor, item );
	}
	if ( descriptor.type === 'terms' || descriptor.type === 'term' ) {
		const names = termNames[ descriptor.source ];
		if ( ! names ) {
			return { ...field, readOnly: true, render: show };
		}
		field.elements = [ ...names ].map( ( [ value, label ] ) => ( {
			value,
			label,
		} ) );
	}
	return field;
}

/**
 * The keys whose value differs from the saved row.
 *
 * @param {Array<Object>} descriptors Panel descriptors.
 * @param {Object}        saved       The row as saved.
 * @param {Object}        data        The form's values.
 * @return {Array<string>} Changed keys.
 */
export function changedKeys( descriptors, saved, data ) {
	return descriptors
		.filter(
			( field ) =>
				field.key in data &&
				comparable( field, data[ field.key ] ) !==
					comparable( field, saved[ field.key ] )
		)
		.map( ( field ) => field.key );
}

/**
 * Names for the term sources the panel's fields use.
 *
 * @param {Array<Object>} descriptors Panel descriptors.
 * @return {Object} source => Map( id => name ).
 */
function usePanelTerms( descriptors ) {
	const [ names, setNames ] = useState( {} );
	const sources = descriptors
		.map( ( field ) => field.source )
		.filter( ( source ) => source && TERM_ROUTES[ source ] )
		.join( ',' );
	useEffect( () => {
		let live = true;
		sources
			.split( ',' )
			.filter( Boolean )
			.forEach( ( source ) =>
				request( TERM_ROUTES[ source ] )
					.then( ( answer ) => {
						if ( live ) {
							const tree = Array.isArray( answer )
								? answer
								: answer?.items;
							setNames( ( current ) => ( {
								...current,
								[ source ]: flattenTree( tree ),
							} ) );
						}
					} )
					.catch( () => {} )
			);
		return () => {
			live = false;
		};
	}, [ sources ] );
	return names;
}

/**
 * The drawer.
 *
 * @param {Object}        props                    Props.
 * @param {Object}        props.item               The grid's row.
 * @param {Array<Object>} props.descriptors        Registry descriptors.
 * @param {Function}      props.commit             Write values (scope.js).
 * @param {Function}      props.onClose            Close the drawer.
 * @param {Function}      props.onExpandVariations Show the variations in the grid, if wired (T67).
 * @return {Element} Drawer.
 */
export default function DetailDrawer( {
	item,
	descriptors,
	commit,
	onClose,
	onExpandVariations,
} ) {
	const fields = useMemo(
		() => panelDescriptors( descriptors ),
		[ descriptors ]
	);
	const roles = useMemo( () => rolesOf( descriptors ), [ descriptors ] );
	const termNames = usePanelTerms( fields );
	const [ saved, setSaved ] = useState( null );
	const [ data, setData ] = useState( null );
	const [ validity, setValidity ] = useState( {} );
	const [ failure, setFailure ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ savedNote, setSavedNote ] = useState( '' );
	const [ asking, setAsking ] = useState( false );
	const [ variations, setVariations ] = useState( [] );
	const [ recent, setRecent ] = useState( undefined );
	const panel = useRef( null );
	const form = useRef( null );
	const closeButton = useRef( null );
	const keepButton = useRef( null );
	const report = useRef( false );
	const id = item.id;

	useEffect( () => {
		let live = true;
		loadProduct(
			item,
			descriptors.map( ( field ) => field.key )
		).then( ( row ) => {
			if ( live ) {
				setSaved( row );
				setData( row );
			}
		} );
		loadVariations( item ).then(
			( rows ) => live && setVariations( rows )
		);
		loadRecentChange( id ).then( ( row ) => live && setRecent( row ) );
		return () => {
			live = false;
		};
		// Loaded once per product opened.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id ] );

	useEffect( () => {
		closeButton.current?.focus();
	}, [] );

	useEffect( () => {
		if ( asking ) {
			keepButton.current?.focus();
		}
	}, [ asking ] );

	// Show the refusals once the controls carry them (their effects run first).
	useEffect( () => {
		if ( report.current ) {
			report.current = false;
			form.current?.reportValidity?.();
		}
	}, [ validity ] );

	const shown = useMemo(
		() => fields.filter( ( field ) => saved && field.key in saved ),
		[ fields, saved ]
	);
	const formFields = useMemo(
		() =>
			saved
				? shown.map( ( field ) => formField( field, saved, termNames ) )
				: [],
		[ shown, saved, termNames ]
	);
	const layout = useMemo(
		() => ( {
			layout: { type: 'regular', labelPosition: 'top' },
			fields: shown.map( ( field ) => field.key ),
		} ),
		[ shown ]
	);
	const changed = saved && data ? changedKeys( shown, saved, data ) : [];
	const dirty = changed.length > 0;

	const requestClose = useCallback( () => {
		if ( dirty && ! saving ) {
			setAsking( true );
			return;
		}
		onClose();
	}, [ dirty, saving, onClose ] );

	const onChange = ( edits ) => {
		setData( ( current ) => ( { ...current, ...edits } ) );
		setSavedNote( '' );
		setValidity( ( current ) => {
			const next = { ...current };
			Object.keys( edits ).forEach( ( key ) => delete next[ key ] );
			return next;
		} );
	};

	const refuse = ( errors ) => {
		const next = {};
		Object.keys( errors ).forEach( ( key ) => {
			next[ key ] = {
				custom: { type: 'invalid', message: errors[ key ] },
			};
		} );
		report.current = true;
		setValidity( next );
	};

	const save = ( event ) => {
		event?.preventDefault();
		if ( saving || ! dirty ) {
			return;
		}
		const errors = {};
		changed.forEach( ( key ) => {
			const field = shown.find( ( each ) => each.key === key );
			const message = validate( field, data[ key ], saved );
			if ( message ) {
				errors[ key ] = message;
			}
		} );
		if ( Object.keys( errors ).length ) {
			refuse( errors );
			return;
		}
		const values = {};
		changed.forEach( ( key ) => {
			const field = shown.find( ( each ) => each.key === key );
			values[ key ] = toRequestValue( field, data[ key ] );
		} );
		setSaving( true );
		setFailure( null );
		commit( item, values, Object.keys( saved ) )
			.then( ( answer ) => {
				const row = { ...saved, ...( answer?.item || {} ) };
				setSaved( row );
				setData( row );
				setValidity( {} );
				setSavedNote(
					sprintf(
						/* translators: %s: the fields saved, separated by commas. */
						__(
							'Saved: %s.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						changed
							.map(
								( key ) =>
									shown.find( ( each ) => each.key === key )
										.label
							)
							.join( ', ' )
					)
				);
			} )
			.catch( ( error ) => {
				const refusals = fieldErrors( error, changed );
				if ( Object.keys( refusals ).length ) {
					refuse( refusals );
					return;
				}
				setFailure( {
					message: sprintf(
						/* translators: 1: a product name, 2: what happened or what to do, as a sentence. */
						__(
							'We could not save the changes to %1$s. %2$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						productName( item, descriptors ),
						failureText(
							error,
							__(
								'Try again in a moment.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						)
					),
					code: error?.code,
					data: error?.data,
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			event.preventDefault();
			if ( asking ) {
				setAsking( false );
				return;
			}
			requestClose();
			return;
		}
		if ( event.key !== 'Tab' || ! panel.current ) {
			return;
		}
		const items = Array.from( panel.current.querySelectorAll( FOCUSABLE ) );
		if ( ! items.length ) {
			return;
		}
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		const at = panel.current.ownerDocument.activeElement;
		if (
			event.shiftKey &&
			( at === first || ! panel.current.contains( at ) )
		) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && at === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	const name = productName( item, descriptors );
	const summary = item[ ROW.attributeSummary ];
	const priceField = descriptors.find( ( field ) => field.type === 'html' );
	const history = ( () => {
		try {
			const url = new URL( panelHref( 'history', window.location.href ) );
			url.searchParams.set( 'product', String( id ) );
			return String( url );
		} catch {
			return '';
		}
	} )();

	let recentLine = '';
	if ( recent === null ) {
		recentLine = __(
			'No changes recorded yet.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	} else if ( recent ) {
		recentLine = sprintf(
			/* translators: 1: a field's name, such as "Regular price", 2: a date. */
			__(
				'%1$s was changed on %2$s.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			recent.field_label || recent.field || '',
			formatDate( recent.applied_gmt )
		);
	}

	return (
		<>
			{ /* A pointer-only shortcut; the close button and Escape do the same. */ }
			{  }
			<div
				className="bcm-drawer__scrim"
				aria-hidden="true"
				onClick={ requestClose }
			/>
			{ /* The drawer's own keys: Escape closes it and Tab stays inside it (§14). */ }
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<aside
				ref={ panel }
				className="bcm-drawer"
				role="dialog"
				aria-modal="true"
				aria-labelledby="bcm-drawer-title"
				onKeyDown={ onKeyDown }
			>
				<header className="bcm-drawer__head">
					<h2 id="bcm-drawer-title">
						{ __(
							'Details',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</h2>
					<button
						ref={ closeButton }
						type="button"
						className="bcm-drawer__close"
						aria-label={ __(
							'Close details',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						onClick={ requestClose }
					>
						<span aria-hidden="true">×</span>
					</button>
				</header>
				<div className="bcm-drawer__body">
					<div className="bcm-drawer__title">
						<h3>{ name }</h3>
						{ summary && (
							<p className="bcm-drawer__hint">{ summary }</p>
						) }
					</div>
					{ asking && (
						<div
							className="bcm-drawer__ask"
							role="alertdialog"
							aria-labelledby="bcm-drawer-ask"
						>
							<p id="bcm-drawer-ask">
								{ __(
									'You have changes that are not saved. Close without saving them?',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</p>
							<Button
								ref={ keepButton }
								variant="secondary"
								onClick={ () => setAsking( false ) }
							>
								{ __(
									'Keep editing',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								onClick={ onClose }
							>
								{ __(
									'Discard changes',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						</div>
					) }
					{ failure && (
						<Notice
							status="error"
							isDismissible
							onRemove={ () => setFailure( null ) }
							className="bcm-error"
						>
							<p>{ failure.message }</p>
						</Notice>
					) }
					{ ! data && (
						<p className="bcm-drawer__loading">
							<Spinner />
							{ __(
								'Loading the product…',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
					) }
					{ data && (
						<form
							ref={ form }
							className="bcm-drawer__form"
							onSubmit={ save }
							noValidate
						>
							<DataForm
								data={ data }
								fields={ formFields }
								form={ layout }
								onChange={ onChange }
								validity={ validity }
							/>
							<div className="bcm-drawer__actions">
								<Button
									variant="primary"
									type="submit"
									isBusy={ saving }
									aria-disabled={ ! dirty || saving }
								>
									{ __(
										'Save',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</Button>
								{ savedNote && (
									<span
										className="bcm-drawer__saved"
										role="status"
									>
										{ savedNote }
									</span>
								) }
							</div>
						</form>
					) }
					{ variations.length > 0 && (
						<section className="bcm-drawer__section">
							<h4>
								{ __(
									'Variations',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</h4>
							<ul className="bcm-drawer__variations">
								{ variations.map( ( row ) => (
									<li key={ row.id }>
										<span>
											{ row[ ROW.attributeSummary ] ||
												( roles.title &&
													row[ roles.title ] ) ||
												`#${ row.id }` }
										</span>
										{ priceField && (
											<span className="bcm-drawer__hint">
												{ textOf(
													row[ priceField.key ]
												) }
											</span>
										) }
									</li>
								) ) }
							</ul>
							{ onExpandVariations && (
								<Button
									variant="link"
									onClick={ () => {
										onClose();
										onExpandVariations( item );
									} }
								>
									{ __(
										'Show the variations in the list',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</Button>
							) }
						</section>
					) }
					<section className="bcm-drawer__section">
						<h4>
							{ __(
								'Recent changes',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</h4>
						<p className="bcm-drawer__hint">
							{ recentLine }{ ' ' }
							{ history && (
								<a href={ history }>
									{ __(
										'See history',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</a>
							) }
						</p>
					</section>
				</div>
			</aside>
		</>
	);
}
