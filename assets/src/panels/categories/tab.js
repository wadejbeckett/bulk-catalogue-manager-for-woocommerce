/**
 * The Categories tab: the same tree as the Catalogue's panel, for browsing
 * and managing categories on their own (SPEC §4.7, §7).
 */
import { useState } from '@wordpress/element';

import CategoryPanel from './category-panel';
import useCategoryTree from './use-category-tree';

/** No grid filters here: the counts are the whole catalogue's. */
const QUERY = {};

/**
 * @param {Object} props
 * @param {Object} props.settings The screen's localised settings.
 * @return {Element} The tab.
 */
export default function CategoriesTab( { settings } ) {
	const tree = useCategoryTree( QUERY );
	const [ value, setValue ] = useState( null );
	return (
		<div className="bulkcat-categories-tab">
			<CategoryPanel
				value={ value }
				onChange={ setValue }
				query={ QUERY }
				tree={ tree }
				settings={ settings }
			/>
		</div>
	);
}
