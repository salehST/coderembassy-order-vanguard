/**
 * Authenticated Order Vanguard REST client.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const boot = window.ceogBoot || {};

if ( boot.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );
}

const route = ( endpoint ) => '/ceog/v1/' + endpoint;

export const fetchSettings = () => apiFetch( { path: route( 'settings' ) } );

export const saveSettings = ( settings ) =>
	apiFetch( {
		path: route( 'settings' ),
		method: 'POST',
		data: { settings },
	} );

export const saveMode = ( mode ) =>
	apiFetch( {
		path: route( 'mode' ),
		method: 'POST',
		data: { mode },
	} );

export const fetchLog = ( filters = {} ) => {
	const query = new URLSearchParams();

	Object.entries( filters ).forEach( ( [ key, value ] ) => {
		if ( value !== '' && value !== undefined && value !== null ) {
			query.set( key, String( value ) );
		}
	} );

	const queryString = query.toString();

	return apiFetch( {
		path: route( 'log' ) + ( queryString ? '?' + queryString : '' ),
	} );
};

export const deleteLog = ( ids ) =>
	apiFetch( {
		path: route( 'log/delete' ),
		method: 'POST',
		data: { ids },
	} );

export const fetchLists = () => apiFetch( { path: route( 'lists' ) } );

export const saveLists = ( lists ) =>
	apiFetch( {
		path: route( 'lists' ),
		method: 'POST',
		data: { lists },
	} );

export const blockEntity = ( type, value ) =>
	apiFetch( {
		path: route( 'block-entity' ),
		method: 'POST',
		data: { type, value },
	} );

export const fetchBreakers = () => apiFetch( { path: route( 'breakers' ) } );

export const fetchDashboard = () => apiFetch( { path: route( 'dashboard' ) } );

export function friendlyError( error ) {
	const status = Number( error?.data?.status || error?.status || 0 );

	if ( status === 401 || status === 403 ) {
		return __(
			'Your admin session expired. Please refresh the page and try again.',
			'coderembassy-order-vanguard'
		);
	}

	return (
		error?.message ||
		__(
			'Order Vanguard could not load this data. Please try again.',
			'coderembassy-order-vanguard'
		)
	);
}
