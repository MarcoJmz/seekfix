/**
 * Entry point of Tools → Broken Links.
 */
import { createRoot } from '@wordpress/element';

import './vendor.css';
import './admin.scss';

import App from './app';

const root = document.getElementById( 'seekfix-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
