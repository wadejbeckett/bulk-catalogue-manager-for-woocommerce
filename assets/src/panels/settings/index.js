/**
 * The Settings tab (SPEC §11, T73): every setting in plain words, read from
 * GET /settings and saved with PUT /settings. Only an administrator
 * (manage_options) may save; anyone else who can open the screen sees the
 * values read-only with a line saying why. A refused save marks each refused
 * setting with the server's sentence and saves nothing.
 */
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { request } from '../../api';
import SettingRow from './row';
import { setSettings } from './store';
import './style.scss';

export {
	useSettings,
	getSettings,
	setSettings,
	subscribeSettings,
	initialPerPage,
	confirmThreshold,
	needsSecondClick,
	applyToVariationsDefault,
	categoryPanelOpen,
} from './store';

/**
 * The values that differ from the saved ones.
 *
 * @param {Object} draft Current values.
 * @param {Object} saved Saved values.
 * @return {Object} Changed values, keyed by setting.
 */
export function changedValues( draft, saved ) {
	return Object.fromEntries(
		Object.entries( draft ).filter(
			( [ key, value ] ) => saved[ key ] !== value
		)
	);
}

export default function Settings() {
	const [ loaded, setLoaded ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let live = true;
		setFailed( false );
		request( 'settings' )
			.then( ( answer ) => {
				if ( live ) {
					setLoaded( answer );
					setDraft( { ...answer.values } );
				}
			} )
			.catch( () => live && setFailed( true ) );
		return () => {
			live = false;
		};
	}, [ attempt ] );

	const changed = useMemo(
		() => ( loaded ? changedValues( draft, loaded.values ) : {} ),
		[ draft, loaded ]
	);
	const dirty = Object.keys( changed ).length > 0;

	const update = useCallback( ( key, value ) => {
		setDraft( ( before ) => ( { ...before, [ key ]: value } ) );
		setErrors( ( before ) => {
			const next = { ...before };
			delete next[ key ];
			return next;
		} );
		setNotice( null );
	}, [] );

	const save = () => {
		setBusy( true );
		setNotice( null );
		request( 'settings', { method: 'PUT', data: { values: changed } } )
			.then( ( answer ) => {
				setLoaded( ( before ) => ( { ...before, ...answer } ) );
				setDraft( { ...answer.values } );
				setErrors( {} );
				setSettings( answer.values );
				setNotice( {
					status: 'success',
					text: __(
						'Settings saved.',
						'bulk-catalogue-manager-for-woocommerce'
					),
				} );
			} )
			.catch( ( error ) => {
				const refused = error?.data?.errors || {};
				setErrors( refused );
				const named = Object.keys( refused ).length > 0;
				setNotice( {
					status: 'error',
					text: named
						? __(
								'Nothing was saved. Correct the settings marked below and save again.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: error?.message ||
							__(
								'The settings could not be saved. Try again in a moment.',
								'bulk-catalogue-manager-for-woocommerce'
							),
				} );
			} )
			.finally( () => setBusy( false ) );
	};

	if ( ! loaded ) {
		return (
			<section className="bulkcat-settings">
				<h2>
					{ __(
						'Settings',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h2>
				{ failed ? (
					<Notice
						status="error"
						isDismissible={ false }
						actions={ [
							{
								label: __(
									'Try again',
									'bulk-catalogue-manager-for-woocommerce'
								),
								onClick: () => setAttempt( ( n ) => n + 1 ),
							},
						] }
					>
						{ __(
							'The settings could not be loaded.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Notice>
				) : (
					<p>
						{ __(
							'Loading settings…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
			</section>
		);
	}

	const readOnly = ! loaded.canEdit;
	// Errors keyed by something that is not a row (a malformed submission).
	const unplaced = Object.keys( errors ).filter(
		( key ) => ! loaded.settings.some( ( setting ) => setting.key === key )
	);

	return (
		<section className="bulkcat-settings">
			<h2>
				{ __( 'Settings', 'bulk-catalogue-manager-for-woocommerce' ) }
			</h2>
			<p className="bulkcat-settings__intro">
				{ readOnly
					? __(
							'Only an administrator can change these settings. You can see how they are set here.',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: __(
							'These settings apply to everyone who uses this screen on this site.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
			</p>
			{ notice && (
				<Notice status={ notice.status } isDismissible={ false }>
					{ notice.text }
					{ unplaced.map( ( key ) => (
						<span
							key={ key }
							className="bulkcat-settings__unplaced"
						>
							{ ' ' }
							{ errors[ key ] }
						</span>
					) ) }
				</Notice>
			) }
			{ /* noValidate: the server's refusal names the setting in plain words. */ }
			<form
				className="bulkcat-settings__form"
				noValidate
				onSubmit={ ( event ) => {
					event.preventDefault();
					if ( ! readOnly && dirty && ! busy ) {
						save();
					}
				} }
			>
				{ loaded.groups.map( ( group ) => {
					const rows = loaded.settings.filter(
						( setting ) => setting.group === group.id
					);
					return (
						rows.length > 0 && (
							<section
								key={ group.id }
								className="bulkcat-settings__group"
								aria-labelledby={ `bulkcat-settings-${ group.id }` }
							>
								<h3 id={ `bulkcat-settings-${ group.id }` }>
									{ group.label }
								</h3>
								{ rows.map( ( setting ) => (
									<SettingRow
										key={ setting.key }
										setting={ setting }
										value={ draft[ setting.key ] }
										error={ errors[ setting.key ] || '' }
										disabled={ readOnly || busy }
										onChange={ ( value ) =>
											update( setting.key, value )
										}
									/>
								) ) }
							</section>
						)
					);
				} ) }
				{ ! readOnly && (
					<div className="bulkcat-settings__actions">
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ busy }
							disabled={ ! dirty || busy }
							accessibleWhenDisabled
						>
							{ busy
								? __(
										'Saving…',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: __(
										'Save settings',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</Button>
					</div>
				) }
			</form>
		</section>
	);
}
