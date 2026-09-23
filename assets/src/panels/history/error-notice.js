/**
 * An error in the §4.8 style: the route's plain sentence in the main text
 * and the technical line (the error code and any details) behind a
 * **Show details** toggle.
 */
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The plain sentence of a failed request.
 *
 * @param {*}      error    The rejection from apiFetch.
 * @param {string} fallback Sentence when the error carries none.
 * @return {string} Sentence.
 */
export function errorMessage( error, fallback ) {
	return error?.message && typeof error.message === 'string'
		? error.message
		: fallback;
}

/**
 * The technical line behind Show details.
 *
 * @param {*} error The rejection from apiFetch.
 * @return {string} Line.
 */
export function errorDetails( error ) {
	const parts = [];
	if ( error?.code ) {
		parts.push( String( error.code ) );
	}
	if ( error?.data?.status ) {
		parts.push( String( error.data.status ) );
	}
	if ( error?.data?.details ) {
		parts.push(
			typeof error.data.details === 'string'
				? error.data.details
				: JSON.stringify( error.data.details )
		);
	}
	if ( error?.data?.reason ) {
		parts.push( String( error.data.reason ) );
	}
	return parts.join( ' · ' );
}

/**
 * The notice.
 *
 * @param {Object}   props          Props.
 * @param {*}        props.error    The rejection.
 * @param {string}   props.fallback Sentence when the error carries none.
 * @param {Function} props.onRemove Dismiss, when dismissible.
 * @return {Element} Notice.
 */
export default function ErrorNotice( { error, fallback, onRemove } ) {
	const [ open, setOpen ] = useState( false );
	const details = errorDetails( error );
	return (
		<Notice
			status="error"
			isDismissible={ !! onRemove }
			onRemove={ onRemove }
			className="bcm-error"
		>
			<p>{ errorMessage( error, fallback ) }</p>
			{ details && (
				<>
					<Button
						variant="link"
						aria-expanded={ open }
						onClick={ () => setOpen( ( was ) => ! was ) }
					>
						{ open
							? __(
									'Hide details',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'Show details',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</Button>
					{ open && (
						<p className="bcm-error__details">{ details }</p>
					) }
				</>
			) }
		</Notice>
	);
}
