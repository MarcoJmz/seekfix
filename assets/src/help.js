/**
 * Short explanations shown at the top of each tab.
 *
 * The longer guide is in the screen's Help tab (see AdminPage::add_help()).
 */

/**
 * Introduction of a tab.
 *
 * @param {Object}  props          Props.
 * @param {Element} props.children Text.
 * @return {Element} Element.
 */
export default function Intro( { children } ) {
	return <div className="seekfix-intro">{ children }</div>;
}
