/**
 * The category panel's public surface (see README.md in this folder); the
 * default export is the Categories tab.
 */
export { default } from './tab';
export { default as CategoryPanel, canManageTerms } from './category-panel';
export {
	default as CategoryManager,
	validateCategory,
} from './category-manager';
export {
	default as CategoryTree,
	CATEGORY_DRAG_TYPE,
	SELECTION_DRAG_TYPE,
} from './category-tree';
export { default as MoveDialog } from './move-dialog';
export { default as useCategoryTree } from './use-category-tree';
export { announce } from './announce';
export { categorySentence, indexTree, nextValue, valueIds } from './tree-model';
