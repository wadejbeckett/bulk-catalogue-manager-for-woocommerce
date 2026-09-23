import { __ } from '@wordpress/i18n';

import Placeholder from './placeholder';

export default function Categories() {
	return (
		<Placeholder
			title={ __(
				'Categories',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		>
			{ __(
				'Your product categories will be arranged on this page.',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		</Placeholder>
	);
}
