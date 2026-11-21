/**
 * Snackbar notices, with an optional Undo action.
 */
import { SnackbarList } from '@wordpress/components';
import {
	createContext,
	useCallback,
	useContext,
	useMemo,
	useState,
} from '@wordpress/element';

const NoticesContext = createContext( () => {} );

let nextId = 1;

/**
 * Provides notify() to the screens and shows the notices.
 *
 * @param {Object}  props          Props.
 * @param {Element} props.children Children.
 * @return {Element} Element.
 */
export function NoticesProvider( { children } ) {
	const [ notices, setNotices ] = useState( [] );

	const remove = useCallback(
		( id ) =>
			setNotices( ( list ) =>
				list.filter( ( notice ) => notice.id !== id )
			),
		[]
	);

	/**
	 * Shows a notice.
	 *
	 * @param {string} content Text.
	 * @param {Object} options `actions` ({label, onClick}[]) and `status` ("error").
	 */
	const notify = useCallback( ( content, options = {} ) => {
		const id = nextId++;

		setNotices( ( list ) => [
			...list,
			{
				id,
				content,
				spokenMessage: content,
				explicitDismiss: options.status === 'error',
				actions: options.actions || [],
			},
		] );
	}, [] );

	const value = useMemo( () => notify, [ notify ] );

	return (
		<NoticesContext.Provider value={ value }>
			{ children }
			<SnackbarList
				className="seekfix-snackbars"
				notices={ notices }
				onRemove={ remove }
			/>
		</NoticesContext.Provider>
	);
}

/**
 * Returns notify( content, options ).
 *
 * @return {Function} notify.
 */
export function useNotify() {
	return useContext( NoticesContext );
}
