/**
 * A panel that arrives in a later ticket: its heading and one plain line.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.title    Heading.
 * @param {Element} props.children The line.
 * @return {Element} The panel.
 */
export default function Placeholder( { title, children } ) {
	return (
		<section className="bcm-placeholder">
			<h2>{ title }</h2>
			<p>{ children }</p>
		</section>
	);
}
