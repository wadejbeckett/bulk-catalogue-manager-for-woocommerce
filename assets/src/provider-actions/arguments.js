/**
 * The same strict scalar predicate used by the server's Arguments normalizer.
 * @param {Object} spec Spec.
 * @param {Object} args Args.
 */
export function argumentActive( spec, args ) {
	const when = spec?.active_when;
	return ! when || when.values.includes( args[ when.argument ] ?? null );
}

/**
 * Change one argument and clear its transitive lookup dependants. Target selection is separate.
 * @param {Object} schema Schema.
 * @param {Object} args   Args.
 * @param {string} key    Key.
 * @param {*}      value  Value.
 */
export function changeArguments( schema, args, key, value ) {
	const next = { ...args, [ key ]: value };
	const changed = new Set( [ key ] );
	let pending = true;
	while ( pending ) {
		pending = false;
		for ( const [ name, spec ] of Object.entries( schema ) ) {
			if ( spec.type !== 'lookup' || changed.has( name ) ) {
				continue;
			}
			if (
				! argumentActive( spec, next ) ||
				[
					...( spec.depends_on || [] ),
					spec.active_when?.argument,
				].some( ( dependency ) => changed.has( dependency ) )
			) {
				next[ name ] = spec.multiple ? [] : null;
				changed.add( name );
				pending = true;
			}
		}
	}
	return next;
}

/**
 * Full declared dependency closure, including predicate controllers.
 * @param {Object} schema Argument declarations.
 * @param {string} name   Argument being looked up.
 * @return {Array<string>} Dependencies in declaration order.
 */
export function argumentDependencies( schema, name ) {
	const needed = new Set();
	const visit = ( key ) => {
		const spec = schema[ key ];
		for ( const dependency of [
			...( spec?.depends_on || [] ),
			spec?.active_when?.argument,
		].filter( Boolean ) ) {
			if ( ! needed.has( dependency ) ) {
				needed.add( dependency );
				visit( dependency );
			}
		}
	};
	visit( name );
	return Object.keys( schema ).filter( ( key ) => needed.has( key ) );
}
