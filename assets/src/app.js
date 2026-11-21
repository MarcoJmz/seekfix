/**
 * The screen: Summary, Links and Settings tabs.
 */
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { NoticesProvider } from './notices';
import SummaryTab from './summary';
import LinksTab from './links';
import SettingsTab from './settings';

const TABS = [
	{ name: 'summary', title: __( 'Summary', 'seekfix' ) },
	{ name: 'links', title: __( 'Links', 'seekfix' ) },
	{ name: 'settings', title: __( 'Settings', 'seekfix' ) },
];

/**
 * Tab from the URL (?tab=links), so a reload keeps it.
 *
 * @return {string} Tab name.
 */
function initialTab() {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );

	return TABS.some( ( item ) => item.name === tab ) ? tab : 'summary';
}

/**
 * Keeps the tab in the URL without reloading.
 *
 * @param {string} tab Tab name.
 */
function rememberTab( tab ) {
	const url = new URL( window.location.href );

	url.searchParams.set( 'tab', tab );
	window.history.replaceState( null, '', url );
}

export default function App() {
	return (
		<NoticesProvider>
			<TabPanel
				className="seekfix-tabs"
				tabs={ TABS }
				initialTabName={ initialTab() }
				onSelect={ rememberTab }
			>
				{ ( tab ) => (
					<div className="seekfix-tab">
						{ tab.name === 'summary' && <SummaryTab /> }
						{ tab.name === 'links' && <LinksTab /> }
						{ tab.name === 'settings' && <SettingsTab /> }
					</div>
				) }
			</TabPanel>
		</NoticesProvider>
	);
}
