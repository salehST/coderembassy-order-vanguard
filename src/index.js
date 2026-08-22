/**
 * Order Guard admin entrypoint.
 */
import { render } from '@wordpress/element';
import App from './App';
import './style.css';

const root = document.getElementById( 'ceog-app' );

if ( root ) {
	render( <App boot={ window.ceogBoot || {} } />, root );
}
