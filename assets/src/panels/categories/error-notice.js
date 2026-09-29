/**
 * A refusal in SPEC §4.8 wording: the plugin's plain sentence as the main
 * text (never a server's own text), and the technical line (code, status,
 * details) behind Show details.
 */
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { plainMessage } from '../../errors';

/**
 * The plain sentence of a failed request: the plugin's own refusal in its
 * words, anything else in the panel's (../../errors.js).
 *
 * @param {Object} error    apiFetch's rejection: { code, message, data }.
 * @param {string} fallback The panel's sentence for anything but the plugin's own refusal.
 * @return {string} Sentence.
 */
export function errorMessage( error, fallback ) {
	return plainMessage( error, fallback );
}

/**
 * The technical line behind Show details.
 *
 * @param {Object} error apiFetch's rejection.
 * @return {string} Line.
 */
export function errorDetails( error ) {
	const parts = [];
	if ( error?.code ) {
		parts.push( String( error.code ) );
	}
	if ( error?.data?.status ) {
		parts.push(
			sprintf(
				/* translators: %d: an HTTP status code such as 400. */
				__( 'HTTP %d', 'bulk-catalogue-manager-for-woocommerce' ),
				error.data.status
			)
		);
	}
	if ( typeof error?.data?.details === 'string' ) {
		parts.push( error.data.details );
	}
	return parts.join( ' · ' );
}

/**
 * The notice.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.error     apiFetch's rejection.
 * @param {string}   props.fallback  Sentence when the answer carried none.
 * @param {Function} props.onDismiss Called to clear it; omit for a fixed notice.
 * @return {Element} Notice.
 */
export default function ErrorNotice( { error, fallback, onDismiss } ) {
	const [ open, setOpen ] = useState( false );
	const details = errorDetails( error );
	return (
		<Notice
			status="error"
			isDismissible={ !! onDismiss }
			onRemove={ onDismiss }
			className="bulkcat-cat-error"
		>
			<p>{ errorMessage( error, fallback ) }</p>
			{ details && (
				<>
					<Button
						variant="link"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
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
						<p className="bulkcat-cat-error__details">
							<code>{ details }</code>
						</p>
					) }
				</>
			) }
		</Notice>
	);
}
