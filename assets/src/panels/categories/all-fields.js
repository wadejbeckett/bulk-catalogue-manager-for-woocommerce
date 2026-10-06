/**
 * All fields (SPEC §4.7): WordPress's own edit form for a product category,
 * shown in the details pane, so every field another plugin adds to that form
 * (search, visibility, custom fields) appears and saves through WordPress
 * itself. The page is the site's own term.php with the plugin's flag, which
 * hides the admin's chrome (src/Admin/TermEmbed.php). When WordPress has saved
 * the form, the pane and the tree are read again.
 */
import { Button, Notice } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { announce } from './announce';

/**
 * The address of WordPress's edit form for a category.
 *
 * @param {Object}  edit  The screen's `categoryEdit`: { url, flag }.
 * @param {number}  id    Category id.
 * @param {boolean} embed Add the flag that shows the form alone.
 * @return {string} URL.
 */
export function termEditUrl( edit, id, embed = false ) {
	return addQueryArgs( edit.url, {
		tag_ID: id,
		...( embed ? { [ edit.flag ]: 1 } : {} ),
	} );
}

/**
 * What a page the form has loaded says about a save. After the form was
 * sent, WordPress shows its notice (`#message`): a success notice when it
 * saved the category, an error notice when it refused. The address says the
 * same (`message=3`, or `error`) until the admin's canonical-URL script
 * tidies it away, so it is read too.
 *
 * @param {Object}   page           The loaded page.
 * @param {string}   page.href      The frame's address.
 * @param {Document} page.doc       The frame's document, when readable.
 * @param {boolean}  page.submitted Whether the form was sent before this load.
 * @return {string|null} 'saved', 'failed' or null when nothing was saved.
 */
export function frameOutcome( { href = '', doc = null, submitted = false } ) {
	const notice = submitted ? doc?.getElementById?.( 'message' ) : null;
	if ( notice ) {
		return notice.classList.contains( 'notice-error' ) ||
			notice.classList.contains( 'error' )
			? 'failed'
			: 'saved';
	}
	let args;
	try {
		args = new URL( href ).searchParams;
	} catch {
		return null;
	}
	if ( args.has( 'error' ) ) {
		return 'failed';
	}
	return args.get( 'message' ) === '3' ? 'saved' : null;
}

/**
 * The frame's address, or '' when it cannot be read (another origin).
 *
 * @param {HTMLIFrameElement} frame The frame.
 * @return {string} URL.
 */
function frameHref( frame ) {
	try {
		return String( frame?.contentWindow?.location?.href || '' );
	} catch {
		return '';
	}
}

/**
 * The embedded form.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.edit    The screen's `categoryEdit`: { url, flag }.
 * @param {number}   props.id      Category id.
 * @param {string}   props.name    Category name.
 * @param {Function} props.onSaved Called after WordPress has saved the form.
 * @param {Function} props.onClose Called to close it.
 * @return {Element} The form.
 */
export default function AllFields( { edit, id, name, onSaved, onClose } ) {
	const frame = useRef( null );
	const submitted = useRef( false );
	const [ outcome, setOutcome ] = useState( null );
	const [ height, setHeight ] = useState( 560 );

	const onLoad = () => {
		const href = frameHref( frame.current );
		let doc = null;
		try {
			doc = frame.current?.contentDocument || null;
		} catch {
			doc = null;
		}
		const tall = doc?.documentElement?.scrollHeight;
		if ( tall ) {
			setHeight( Math.max( 320, tall + 8 ) );
		}
		const result = frameOutcome( {
			href,
			doc,
			submitted: submitted.current,
		} );
		submitted.current = false;
		// Know when the form is sent, so the next page is read as its answer.
		doc?.getElementById?.( 'edittag' )?.addEventListener( 'submit', () => {
			submitted.current = true;
		} );
		setOutcome( result );
		if ( result === 'saved' ) {
			announce(
				__(
					'Saved through WordPress’s category form.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			);
			onSaved();
		} else if ( result === 'failed' ) {
			announce(
				__(
					'WordPress could not save the category.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				'assertive'
			);
		}
	};

	const title = sprintf(
		/* translators: %s: a category name. */
		__( 'All fields for %s', 'bulk-catalogue-manager-for-woocommerce' ),
		name
	);

	return (
		<section
			className="bulkcat-cat-fields"
			aria-labelledby={ `bulkcat-cat-fields-${ id }` }
		>
			<div className="bulkcat-cat-fields__head">
				<h3
					id={ `bulkcat-cat-fields-${ id }` }
					className="bulkcat-cat-pane__subtitle"
				>
					{ __(
						'All fields',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h3>
				<p className="bulkcat-cat-pane__hint">
					{ __(
						'WordPress’s own form for this category, with every field other plugins add to it. Update saves it as WooCommerce’s category screen does.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
				<Button variant="tertiary" size="compact" onClick={ onClose }>
					{ __(
						'Close all fields',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			</div>
			<div className="bulkcat-cat-fields__status" role="status">
				{ outcome === 'saved' && (
					<Notice status="success" isDismissible={ false }>
						{ __(
							'Saved through WordPress’s category form. The details and the tree are up to date.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Notice>
				) }
				{ outcome === 'failed' && (
					<Notice status="error" isDismissible={ false }>
						{ __(
							'WordPress could not save the category. The form below says why.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Notice>
				) }
			</div>
			<iframe
				ref={ frame }
				className="bulkcat-cat-fields__frame"
				title={ title }
				src={ termEditUrl( edit, id, true ) }
				style={ { height } }
				onLoad={ onLoad }
			/>
		</section>
	);
}
