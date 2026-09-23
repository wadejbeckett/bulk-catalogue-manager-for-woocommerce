/**
 * The scope line on History and Audit log (SPEC §9.3, §4.6; T59): only
 * changes made through this screen are recorded. Ordinary content, not an
 * error notice, shown with the populated list and the empty one alike.
 */
import { __ } from '@wordpress/i18n';

export default function ScopeNotice() {
	return (
		<p className="bcm-scope-notice">
			{ __(
				'Only changes made through this screen are recorded here. Edits made elsewhere in WordPress, such as on the product edit page, are not.',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		</p>
	);
}
