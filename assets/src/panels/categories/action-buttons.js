/**
 * The workspace's registered actions as buttons (workspace-actions.js), in
 * the details pane and the selection pane: one button each, except an
 * add-on's actions, which share one menu button per group ("Sample
 * picks…", 1.4.1) so a provider with many actions does not crowd the pane.
 */
import { Button } from '@wordpress/components';

import { ProviderMenuButton } from '../../components/provider-menu';
import { offeredCategoryActions } from './workspace-actions';

/**
 * @param {Object}        props          Props.
 * @param {Array<Object>} props.actions  Registered actions (categoryActions()).
 * @param {Array<number>} props.ids      The categories they act on.
 * @param {Function}      props.onAction Receives ( action, ids ).
 * @return {Element} The buttons.
 */
export default function ActionButtons( { actions, ids, onAction } ) {
	return offeredCategoryActions( actions ).map( ( offered ) =>
		offered.menu ? (
			<ProviderMenuButton
				key={ offered.menu.key }
				label={ offered.menu.label }
				items={ offered.actions.map( ( action ) => ( {
					key: action.key,
					label: action.label,
					onClick: () => onAction( action, ids ),
				} ) ) }
			/>
		) : (
			<Button
				key={ offered.action.key }
				variant="secondary"
				size="compact"
				isDestructive={ !! offered.action.isDestructive }
				onClick={ () => onAction( offered.action, ids ) }
			>
				{ offered.action.label }
			</Button>
		)
	);
}
