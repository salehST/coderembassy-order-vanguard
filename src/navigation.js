/**
 * Hash routing for the standalone plugin workspace.
 */
import { __ } from '@wordpress/i18n';
import {
	Activity,
	BadgeHelp,
	Blocks,
	Database,
	LayoutDashboard,
	ListFilter,
	Settings,
	ShieldAlert,
} from 'lucide-react';

export const DEFAULT_ROUTE = 'dashboard';

export const NAV_ITEMS = [
	{
		id: 'dashboard',
		label: () => __( 'Dashboard', 'coderembassy-order-vanguard' ),
		icon: LayoutDashboard,
	},
	{
		id: 'activity-log',
		label: () => __( 'Activity Log', 'coderembassy-order-vanguard' ),
		icon: Activity,
	},
	{
		id: 'circuit-breakers',
		label: () => __( 'Circuit Breakers', 'coderembassy-order-vanguard' ),
		icon: ShieldAlert,
	},
	{
		id: 'store-api',
		label: () => __( 'Store API Guard', 'coderembassy-order-vanguard' ),
		icon: Blocks,
	},
	{
		id: 'lists',
		label: () => __( 'Lists', 'coderembassy-order-vanguard' ),
		icon: ListFilter,
	},
	{
		id: 'settings',
		label: () => __( 'Settings', 'coderembassy-order-vanguard' ),
		icon: Settings,
	},
	{
		id: 'privacy-logs',
		label: () => __( 'Privacy & Logs', 'coderembassy-order-vanguard' ),
		icon: Database,
	},
	{
		id: 'help',
		label: () => __( 'Help', 'coderembassy-order-vanguard' ),
		icon: BadgeHelp,
	},
];

export function readRoute() {
	const requested = window.location.hash
		.replace( /^#\/?/, '' )
		.split( '?' )[ 0 ];
	return NAV_ITEMS.some( ( item ) => item.id === requested )
		? requested
		: DEFAULT_ROUTE;
}

export function navigateTo( route ) {
	window.location.hash = '#/' + route;
}

export function getNavItem( route ) {
	return NAV_ITEMS.find( ( item ) => item.id === route ) || NAV_ITEMS[ 0 ];
}
