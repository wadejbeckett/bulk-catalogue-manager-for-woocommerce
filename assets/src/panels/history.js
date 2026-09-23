import { __ } from '@wordpress/i18n';

import Placeholder from './placeholder';

export default function History() {
	return (
		<Placeholder
			title={ __( 'History', 'bulk-catalogue-manager-for-woocommerce' ) }
		>
			{ __(
				'Every change made here will be listed on this page, with a way to undo it.',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		</Placeholder>
	);
}
