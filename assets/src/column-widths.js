/**
 * Column widths the user sets by dragging the edge of a table header.
 *
 * DataViews has no column resizing, so this adds it from outside:
 * - A handle is rendered into each header cell with a portal. The header
 *   order is fixed: checkbox, primary column, the view's fields, actions.
 * - Field widths go in view.layout.styles (width, minWidth and maxWidth).
 * - The primary column ignores those styles, and DataViews makes it take the
 *   width the other columns leave (their <col> elements are width: 1%). Its
 *   width goes on its <col> instead, and the actions column then takes the
 *   rest (see .has-primary-width in admin.scss).
 * - Widths are kept in localStorage, so they survive reloads in this browser.
 */
import {
	createPortal,
	useCallback,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, isRTL, sprintf } from '@wordpress/i18n';

const STORAGE_KEY = 'seekfix_column_widths';

/**
 * Narrowest width a column can be dragged to, in pixels.
 */
export const MIN_WIDTH = 60;

const KEY_STEP = 10;
const KEY_STEP_LARGE = 50;

/**
 * Saved widths, keyed by field ID.
 *
 * @return {Object<string, number>} Widths.
 */
function load() {
	try {
		const saved = JSON.parse( window.localStorage.getItem( STORAGE_KEY ) );

		return saved && typeof saved === 'object' ? saved : {};
	} catch {
		return {};
	}
}

/**
 * Saves the widths. Storage can be full or blocked; widths then last until
 * the page is reloaded.
 *
 * @param {Object<string, number>} widths Widths.
 */
function save( widths ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( widths ) );
	} catch {}
}

/**
 * Widths state. resize() changes it while dragging; commit() saves it.
 *
 * @return {Object} widths, resize( id, width ), commit(), reset( id ) and
 *                  resetAll().
 */
export function useColumnWidths() {
	const [ widths, setWidths ] = useState( load );
	const latest = useRef( widths );

	const update = useCallback( ( next, persist ) => {
		latest.current = next;
		setWidths( next );

		if ( persist ) {
			save( next );
		}
	}, [] );

	const resize = useCallback(
		( id, width ) =>
			update( {
				...latest.current,
				[ id ]: Math.max( MIN_WIDTH, Math.round( width ) ),
			} ),
		[ update ]
	);

	const commit = useCallback( () => save( latest.current ), [] );

	const reset = useCallback(
		( id ) => {
			const { [ id ]: removed, ...rest } = latest.current;

			update( rest, true );
		},
		[ update ]
	);

	const resetAll = useCallback( () => update( {}, true ), [ update ] );

	return { widths, resize, commit, reset, resetAll };
}

/**
 * The view with the widths of its fields applied.
 *
 * @param {Object}                 view   View.
 * @param {Object<string, number>} widths Widths.
 * @return {Object} View.
 */
export function applyWidths( view, widths ) {
	const styles = { ...( view.layout?.styles || {} ) };

	Object.entries( widths ).forEach( ( [ id, width ] ) => {
		if ( id !== view.titleField ) {
			styles[ id ] = {
				...styles[ id ],
				width,
				minWidth: width,
				maxWidth: width,
			};
		}
	} );

	return { ...view, layout: { ...view.layout, styles } };
}

/**
 * Class and style for the table's container that size the primary column.
 *
 * @param {Object}                 view   View.
 * @param {Object<string, number>} widths Widths.
 * @return {Object} className and style, empty when it has no width.
 */
export function primaryWidthProps( view, widths ) {
	const width = widths[ view.titleField ];

	return width
		? {
				className: 'has-primary-width',
				style: { '--seekfix-primary-width': `${ width }px` },
			}
		: {};
}

/**
 * One drag handle, rendered into a header cell.
 *
 * @param {Object}                              props          Props.
 * @param {Element}                             props.th       Header cell.
 * @param {string}                              props.id       Field ID.
 * @param {string}                              props.label    Column label.
 * @param {Object}                              props.widths   Widths state from useColumnWidths().
 * @param {(id: string, width: number) => void} props.onResize Called while resizing.
 * @return {Element} Portal.
 */
function Handle( { th, id, label, widths, onResize } ) {
	const drag = useRef( null );
	const [ dragging, setDragging ] = useState( false );
	const direction = isRTL() ? -1 : 1;
	const current = () => th.getBoundingClientRect().width;

	const onPointerDown = ( event ) => {
		if ( event.button !== 0 ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		try {
			// Keeps the drag going when the pointer leaves the thin handle.
			event.currentTarget.setPointerCapture( event.pointerId );
		} catch {
			// The pointer is no longer active; the drag still works over the
			// handle.
		}

		drag.current = { x: event.clientX, width: current() };
		setDragging( true );
	};

	const onPointerMove = ( event ) => {
		if ( drag.current ) {
			onResize(
				id,
				drag.current.width +
					direction * ( event.clientX - drag.current.x )
			);
		}
	};

	const onPointerUp = () => {
		if ( drag.current ) {
			drag.current = null;
			setDragging( false );
			widths.commit();
		}
	};

	const onKeyDown = ( event ) => {
		const step = event.shiftKey ? KEY_STEP_LARGE : KEY_STEP;
		const grow = { ArrowRight: step, ArrowLeft: -step }[ event.key ];

		if ( grow !== undefined ) {
			event.preventDefault();
			onResize( id, current() + direction * grow );
			widths.commit();
		} else if ( event.key === 'Home' || event.key === 'Delete' ) {
			event.preventDefault();
			widths.reset( id );
		}
	};

	return createPortal(
		// A focusable separator with a value is the ARIA 1.2 pattern for a
		// resize handle; the a11y plugin still counts it as non-interactive.
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<span
			className={ `seekfix-col-resizer${
				dragging ? ' is-dragging' : ''
			}` }
			role="separator"
			aria-orientation="vertical"
			aria-label={ sprintf(
				/* translators: %s: column name. */
				__( 'Resize the %s column', 'seekfix' ),
				label
			) }
			aria-valuenow={ Math.round( widths.widths[ id ] || current() ) }
			aria-valuemin={ MIN_WIDTH }
			title={ __( 'Drag to resize. Double-click to reset.', 'seekfix' ) }
			tabIndex={ 0 }
			onPointerDown={ onPointerDown }
			onPointerMove={ onPointerMove }
			onPointerUp={ onPointerUp }
			onPointerCancel={ onPointerUp }
			onClick={ ( event ) => event.stopPropagation() }
			onDoubleClick={ () => widths.reset( id ) }
			onKeyDown={ onKeyDown }
		/>,
		th
	);
}

/**
 * Drag handles for every column of the table in a container.
 *
 * @param {Object}   props           Props.
 * @param {?Element} props.container Element that holds the table.
 * @param {string[]} props.columns   Field IDs in header order: primary first.
 * @param {Object}   props.labels    Column labels, keyed by field ID.
 * @param {Object}   props.widths    Widths state from useColumnWidths().
 * @return {Element[]} Handles.
 */
export function ColumnResizers( { container, columns, labels, widths } ) {
	const [ headers, setHeaders ] = useState( [] );
	const count = columns.length;

	// DataViews draws and redraws the table on its own updates (loading,
	// paging, column changes), which do not re-render this component. Watch
	// the container and look for the header cells whenever it changes; the
	// state only changes when the cells do, so the handles added by the
	// portals do not cause a loop.
	useLayoutEffect( () => {
		const root = container;

		if ( ! root ) {
			return undefined;
		}

		const scan = () => {
			// Data cells only: the primary column, then the fields.
			const cells = [
				...root.querySelectorAll( '.dataviews-view-table thead th' ),
			].filter(
				( th ) =>
					! th.classList.contains(
						'dataviews-view-table__checkbox-column'
					) &&
					! th.classList.contains(
						'dataviews-view-table__actions-column'
					)
			);
			const next = cells.length === count ? cells : [];

			setHeaders( ( previous ) =>
				previous.length === next.length &&
				previous.every( ( th, index ) => th === next[ index ] )
					? previous
					: next
			);
		};

		scan();

		const observer = new window.MutationObserver( scan );

		observer.observe( root, { childList: true, subtree: true } );

		return () => observer.disconnect();
	}, [ container, count ] );

	return headers.map( ( th, index ) => (
		<Handle
			key={ columns[ index ] }
			th={ th }
			id={ columns[ index ] }
			label={ labels[ columns[ index ] ] || columns[ index ] }
			widths={ widths }
			onResize={ widths.resize }
		/>
	) );
}
