/**
 * Stand-in for the react-jsx-runtime script on WordPress before 6.6.
 *
 * Built code calls jsx() and jsxs(); both map to createElement() of the
 * React that WordPress loads (imported as an external, so the asset file
 * lists the react script as a dependency). Only registered when WordPress
 * lacks the script: see AdminPage::register_jsx_runtime().
 */
// React is the copy WordPress loads (external), not an npm dependency.
// eslint-disable-next-line import/no-extraneous-dependencies
import { createElement, Fragment } from 'react';

/**
 * One child, or children built at run time (an array that needs keys).
 *
 * @param {*}      type  Element type.
 * @param {Object} props Props, with children.
 * @param {string} key   Key.
 * @return {Object} Element.
 */
function jsx( type, props, key ) {
	return createElement( type, key === undefined ? props : { ...props, key } );
}

/**
 * Static children written in JSX: passed as separate arguments, as
 * createElement() expects them, so React does not ask them for keys.
 *
 * @param {*}      type  Element type.
 * @param {Object} props Props, with a children array.
 * @param {string} key   Key.
 * @return {Object} Element.
 */
function jsxs( type, props, key ) {
	const { children, ...rest } = props;

	if ( key !== undefined ) {
		rest.key = key;
	}

	return createElement( type, rest, ...children );
}

window.ReactJSXRuntime = {
	Fragment,
	jsx,
	jsxs,
};
