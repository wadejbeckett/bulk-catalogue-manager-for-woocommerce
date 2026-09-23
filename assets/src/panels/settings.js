import { __ } from '@wordpress/i18n';

import Placeholder from './placeholder';

export default function Settings() {
	return (
		<Placeholder
			title={ __( 'Settings', 'bulk-catalogue-manager-for-woocommerce' ) }
		>
			{ __(
				'The settings for this screen will be changed on this page.',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		</Placeholder>
	);
}
