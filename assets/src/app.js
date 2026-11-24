/**
 * The screen: Summary, Links, History and Settings tabs.
 */
import { TabPanel } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { replaceArgs } from './navigation';
import { NoticesProvider } from './notices';
import SummaryTab from './summary';
import LinksTab from './links';
import HistoryTab from './history';
import SettingsTab from './settings';

const TABS = [
	{ name: 'summary', title: __( 'Summary', 'seekfix' ) },
	{ name: 'links', title: __( 'Links', 'seekfix' ) },
	{ name: 'history', title: __( 'History', 'seekfix' ) },
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
	replaceArgs( { tab } );
}

export default function App() {
	// TabPanel only takes an initial tab, so opening a tab from code remounts it.
	const [ mount, setMount ] = useState( 0 );

	const openLinks = useCallback( ( status ) => {
		replaceArgs( { tab: 'links', status } );
		setMount( ( n ) => n + 1 );
	}, [] );

	return (
		<NoticesProvider>
			<TabPanel
				key={ mount }
				className="seekfix-tabs"
				tabs={ TABS }
				initialTabName={ initialTab() }
				onSelect={ rememberTab }
			>
				{ ( tab ) => (
					<div className="seekfix-tab">
						{ tab.name === 'summary' && (
							<SummaryTab onOpenLinks={ openLinks } />
						) }
						{ tab.name === 'links' && <LinksTab /> }
						{ tab.name === 'history' && <HistoryTab /> }
						{ tab.name === 'settings' && <SettingsTab /> }
					</div>
				) }
			</TabPanel>
		</NoticesProvider>
	);
}
