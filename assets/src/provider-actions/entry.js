/**
 * Entry builders retain native tuple identity and isolate completion from other selections.
 * @param {Object} ref      Ref.
 * @param {string} actionId ActionId.
 */
export function rowEntry( ref, actionId ) {
	const family = ref.kind === 'term' ? 'category' : 'product';
	return {
		location: family + '.row',
		selection: { targets: [ { ...ref } ] },
		actionId,
		selectionKey: family + ':row:' + JSON.stringify( ref ),
		clearSubmitted: false,
	};
}
export function bulkEntry( selection, selectionKey, kind ) {
	if (
		! [ 'product', 'category' ].includes( kind ) ||
		! selection ||
		Object.keys( selection ).length !== 1 ||
		! (
			selection.targets?.length ||
			selection.token ||
			selection.typed_token
		)
	) {
		throw new Error( 'A nonempty exact selection is required.' );
	}
	return {
		location: kind + '.bulk',
		selection,
		selectionKey: kind + ':bulk:' + selectionKey,
		clearSubmitted: true,
	};
}
