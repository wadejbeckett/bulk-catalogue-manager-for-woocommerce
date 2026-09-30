/** Visible category creation feedback, owned outside the edit modal. */
import { Button, Notice } from '@wordpress/components';
import { useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * The confirmed receipt, reconciled by the shared tree owner.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.creation  Confirmed receipt and readback phase, or null.
 * @param {Function} props.onRetry   Read the tree again; never repeats the write.
 * @param {Function} props.onDismiss Hide the receipt without changing the tree.
 * @return {Element|null} Visible polite notice.
 */
export default function CreationNotice( { creation, onRetry, onDismiss } ) {
	const notice = useRef( null );
	const retry = useRef( null );
	const retryRef = useCallback( ( button ) => {
		// Only move focus when the focused retry actually disappears. A read
		// completing while the user works elsewhere must not steal focus.
		if (
			! button &&
			retry.current === retry.current?.ownerDocument.activeElement
		) {
			notice.current?.focus();
		}
		retry.current = button;
	}, [] );
	if ( ! creation ) {
		return null;
	}
	const { name, phase } = creation;
	const messages = {
		refreshing: sprintf(
			/* translators: %s: a category name. */
			__(
				'Category “%s” was created. Refreshing the category list…',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		),
		visible: sprintf(
			/* translators: %s: a category name. */
			__(
				'Category “%s” was created.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		),
		missing: sprintf(
			/* translators: %s: a category name. */
			__(
				'Category “%s” was created, but is missing from the refreshed category list. You do not need to create it again.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		),
		refresh_failed: sprintf(
			/* translators: %s: a category name. */
			__(
				'Category “%s” was created, but the category list could not refresh.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		),
	};
	const status =
		phase === 'missing' || phase === 'refresh_failed'
			? 'warning'
			: 'success';
	return (
		<div
			ref={ notice }
			role="status"
			aria-live="polite"
			aria-atomic="true"
			tabIndex={ -1 }
		>
			{ /* The visible live region announces once; disable Notice's separate speaker. */ }
			<Notice status={ status } onRemove={ onDismiss } spokenMessage="">
				<p>{ messages[ phase ] }</p>
				{ phase !== 'visible' && (
					<Button
						ref={ retryRef }
						variant="secondary"
						onClick={ onRetry }
						disabled={ phase === 'refreshing' }
						accessibleWhenDisabled
					>
						{ __(
							'Refresh category list',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
			</Notice>
		</div>
	);
}

/**
 * An unconfirmed response offers a read, without claiming a creation or retrying it.
 *
 * @param {Object}   props         Props.
 * @param {Function} props.onRetry Read the category list.
 * @param {boolean}  props.loading Whether the read is pending.
 * @return {Element} Visible polite warning.
 */
export function IncompleteCreationNotice( { onRetry, loading } ) {
	return (
		<div role="status" aria-live="polite" aria-atomic="true">
			<Notice status="warning" isDismissible={ false } spokenMessage="">
				<p>
					{ __(
						'The server returned an incomplete save response. Check the category list before creating it again.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
				<Button
					variant="secondary"
					onClick={ onRetry }
					disabled={ loading }
					accessibleWhenDisabled
				>
					{ __(
						'Refresh category list',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			</Notice>
		</div>
	);
}
