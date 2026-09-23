/**
 * The bulk change panel's public surface (T48–T50). The catalogue panel
 * mounts `<BulkChangePanel>`; undo and category batches reuse the
 * confirmation policy through `useConfirmApply` and `<ConfirmApply>`.
 * See README.md for the props.
 */
export { default, default as BulkChangePanel } from './panel';
export {
	ConfirmApply,
	alwaysConfirmReasons,
	confirmPolicy,
	useConfirmApply,
} from './confirm';
export { PreviewResult } from './preview';
