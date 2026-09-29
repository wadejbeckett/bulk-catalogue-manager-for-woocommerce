/**
 * An error in the §4.8 style: the plugin's own refusal in its words, or the
 * panel's plain sentence (never a server's own text: ../../errors.js), and
 * the technical line (the error code and any details) behind a
 * **Show details** toggle.
 */
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { plainMessage } from '../../errors';

/**
 * The plain sentence of a failed request.
 *
 * @param {*}      error    The rejection from apiFetch.
 * @param {string} fallback The panel's sentence for anything but the plugin's own refusal.
 * @return {string} Sentence.
 */
export function errorMessage( error, fallback ) {
	return plainMessage( error, fallback );
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
 * @param {string}   props.fallback The panel's sentence for anything but the plugin's own refusal.
 * @param {string}   props.message  The screen's own sentence, shown whatever the error (optional).
 * @param {Function} props.onRemove Dismiss, when dismissible.
 * @return {Element} Notice.
 */
export default function ErrorNotice( { error, fallback, message, onRemove } ) {
	const [ open, setOpen ] = useState( false );
	const details = errorDetails( error );
	return (
		<Notice
			status="error"
			isDismissible={ !! onRemove }
			onRemove={ onRemove }
			className="bulkcat-error"
		>
			<p>{ message || errorMessage( error, fallback ) }</p>
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
						<p className="bulkcat-error__details">{ details }</p>
					) }
				</>
			) }
		</Notice>
	);
}
