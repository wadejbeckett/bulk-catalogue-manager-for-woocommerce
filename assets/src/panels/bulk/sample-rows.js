/**
 * The preview sample as one row per product or variation (ticket 133 bug
 * 4): the route lists each object once per step, so a change of two steps
 * on 15 products came back as 30 rows, and a second step on the same field
 * showed the first step's result as its "Now". Grouped here, each object is
 * one row holding its steps; steps on the same field are one line from the
 * value before the first to the value after the last.
 */

/**
 * Whether a step's line may take a later step on the same field.
 *
 * @param {Object} row Sample row.
 * @return {boolean} True for a native change or an already-correct value.
 */
function mergeable( row ) {
	return ! row.external && [ 'changed', 'already' ].includes( row.state );
}

/**
 * The sample's rows grouped by object, in the order the route gave them.
 *
 * @param {Array<Object>} rows Sample rows, one per object and step.
 * @return {Array<{key: string, object_id: number, object_type: string, name: string, state: string, lines: Array<Object>}>} One entry per object.
 */
export function groupSample( rows ) {
	const groups = [];
	const byKey = new Map();
	( rows || [] ).forEach( ( row ) => {
		const key = `${ row.object_type }:${ row.object_id }`;
		let group = byKey.get( key );
		if ( ! group ) {
			group = {
				key,
				object_id: row.object_id,
				object_type: row.object_type,
				name: row.name,
				lines: [],
			};
			byKey.set( key, group );
			groups.push( group );
		}
		const line = mergeable( row )
			? group.lines.find(
					( each ) => each.field === row.field && mergeable( each )
				)
			: null;
		if ( line ) {
			line.new = row.new;
			line.state =
				line.state === 'changed' || row.state === 'changed'
					? 'changed'
					: 'already';
			line.replaces = line.replaces || row.replaces;
			line.note = row.note || line.note;
			line.context = row.context || line.context;
			return;
		}
		group.lines.push( { ...row } );
	} );
	groups.forEach( ( group ) => {
		group.state = group.lines.some( ( line ) => line.state === 'changed' )
			? 'changed'
			: group.lines[ 0 ].state;
	} );
	return groups;
}
