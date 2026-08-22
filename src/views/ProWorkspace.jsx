/**
 * Mount point supplied by the active Pro add-on.
 */
import { useEffect } from '@wordpress/element';

export default function ProWorkspace( { view = 'cleanup' } ) {
	useEffect( () => {
		window.dispatchEvent( new CustomEvent( 'ceog-pro-mount', { detail: { view } } ) );

		return () => {
			window.dispatchEvent( new CustomEvent( 'ceog-pro-unmount' ) );
		};
	}, [ view ] );

	return (
		<div
			id="ceog-pro-app"
			className="ceog-pro-app ceog-pro-app--embedded"
			data-ceog-pro-view={ view }
		/>
	);
}
