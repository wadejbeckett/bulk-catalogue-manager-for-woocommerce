import { useEffect, useId, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useChoices } from './use-choices';

/**
 * Native bounded choice input; only value IDs are submitted to the preview.
 * @param {Object}   root0              Root0.
 * @param {Object}   root0.schema       Schema.
 * @param {*}        root0.value        Value.
 * @param {Function} root0.onChange     OnChange.
 * @param {string}   root0.provider     Provider.
 * @param {string}   root0.action       Action.
 * @param {string}   root0.argument     Argument.
 * @param {Object}   root0.selection    Selection.
 * @param {Object}   root0.dependencies Dependencies.
 * @param {Function} root0.onInvalidate OnInvalidate.
 */
export default function ChoiceControl( {
	schema,
	value,
	onChange,
	provider,
	action,
	argument,
	selection,
	dependencies,
	onInvalidate,
} ) {
	const id = useId();
	const input = useRef( null );
	let values = value || [];
	if ( ! schema.multiple ) {
		values = value ? [ value ] : [];
	}
	const choices = useChoices( {
		provider,
		action,
		argument,
		selection,
		dependencies,
		selected: values,
	} );
	const dependencyKey = JSON.stringify( [
		provider,
		action,
		argument,
		selection,
		dependencies,
	] );
	const previous = useRef( dependencyKey );
	const labels = JSON.stringify( choices.selected );
	const lastLabels = useRef( '' );
	useEffect( () => {
		if ( previous.current !== dependencyKey ) {
			previous.current = dependencyKey;
			lastLabels.current = '';
			if ( values.length ) {
				onChange( schema.multiple ? [] : null );
			}
			onInvalidate?.();
		}
	}, [
		dependencyKey,
		onChange,
		onInvalidate,
		schema.multiple,
		values.length,
	] );
	useEffect( () => {
		if ( ! choices.loading && ! choices.error ) {
			if ( lastLabels.current !== labels && choices.selected.length ) {
				onInvalidate?.();
			}
			lastLabels.current = labels;
		}
	}, [
		labels,
		choices.selected.length,
		choices.loading,
		choices.error,
		onInvalidate,
	] );

	const toggle = ( chosen, on ) => {
		let next = on ? chosen : null;
		if ( schema.multiple ) {
			next = on
				? [ ...new Set( [ ...values, chosen ] ) ]
				: values.filter( ( item ) => item !== chosen );
		}
		onChange( next );
	};
	let status = __( 'No matches.', 'bulk-catalogue-manager-for-woocommerce' );
	if ( choices.loading ) {
		status = __(
			'Loading choices…',
			'bulk-catalogue-manager-for-woocommerce'
		);
	} else if ( choices.error ) {
		status = __(
			'Choices could not be loaded.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	} else if ( choices.items.length ) {
		status = sprintf(
			/* translators: %d: number of choices on the loaded pages. */ __(
				'%d choices loaded.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			choices.items.length
		);
	}

	return (
		<fieldset
			className="bulkcat-bulk__fg is-full"
			aria-busy={ choices.loading }
		>
			<legend>{ schema.label }</legend>
			<label htmlFor={ id }>
				{ __(
					'Search choices',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</label>
			<input
				ref={ input }
				id={ id }
				type="search"
				maxLength={ 128 }
				onChange={ ( event ) => choices.search( event.target.value ) }
			/>
			<p role="status" aria-live="polite">
				{ status }
			</p>
			{ choices.error && (
				<div role="alert">
					<p>
						{ __(
							'The choices could not be loaded. Try again.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
					<button type="button" onClick={ choices.retry }>
						{ __(
							'Try again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				</div>
			) }
			<ul
				aria-label={ __(
					'Selected choices',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ choices.selected.map( ( item ) => (
					<li key={ item.value }>
						<span>
							{ item.available
								? item.label
								: __(
										'Unavailable choice — choose again and preview.',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</span>
						<button
							type="button"
							onClick={ () => {
								toggle( item.value, false );
								input.current?.focus();
							} }
						>
							{ __(
								'Remove',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</button>
					</li>
				) ) }
			</ul>
			<ul>
				{ choices.items.map( ( item, index ) => (
					<li key={ item.value }>
						<label htmlFor={ id + '-choice-' + index }>
							<input
								id={ id + '-choice-' + index }
								type={ schema.multiple ? 'checkbox' : 'radio' }
								name={ id + '-choice' }
								checked={ values.includes( item.value ) }
								disabled={
									schema.multiple &&
									! values.includes( item.value ) &&
									values.length >= schema.max_items
								}
								onChange={ ( event ) =>
									toggle( item.value, event.target.checked )
								}
							/>
							{ item.label }
						</label>
					</li>
				) ) }
			</ul>
			{ choices.hasMore && (
				<button
					type="button"
					onClick={ choices.more }
					disabled={ choices.loading }
				>
					{ __(
						'Load more',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</button>
			) }
		</fieldset>
	);
}
