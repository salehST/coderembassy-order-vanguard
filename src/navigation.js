/**
 * Hash routing and the locked v1 navigation.
 */
import { __ } from '@wordpress/i18n';
import {
	Activity,
	BadgeHelp,
	BellRing,
	Blocks,
	Cloud,
	Database,
	History,
	KeyRound,
	LayoutDashboard,
	ListFilter,
	Settings,
	ShieldBan,
	ShieldAlert,
	Trash2,
} from 'lucide-react';

export const DEFAULT_ROUTE = 'dashboard';

export const NAV_ITEMS = [
	{
		id: 'dashboard',
		label: () => __( 'Dashboard', 'coderembassy-order-guard' ),
		icon: LayoutDashboard,
	},
	{
		id: 'activity-log',
		label: () => __( 'Activity Log', 'coderembassy-order-guard' ),
		icon: Activity,
	},
	{
		id: 'history-reporting',
		label: () => __( 'History & Reports', 'coderembassy-order-guard' ),
		icon: History,
		proOnly: true,
	},
	{
		id: 'circuit-breakers',
		label: () => __( 'Circuit Breakers', 'coderembassy-order-guard' ),
		icon: ShieldAlert,
	},
	{
		id: 'store-api',
		label: () => __( 'Store API Guard', 'coderembassy-order-guard' ),
		icon: Blocks,
	},
	{
		id: 'turnstile',
		label: () => __( 'Turnstile', 'coderembassy-order-guard' ),
		icon: Cloud,
		proOnly: true,
	},
	{
		id: 'alerts',
		label: () => __( 'Alerts', 'coderembassy-order-guard' ),
		icon: BellRing,
		proOnly: true,
	},
	{
		id: 'lists',
		label: () => __( 'Lists', 'coderembassy-order-guard' ),
		icon: ListFilter,
	},
	{
		id: 'settings',
		label: () => __( 'Settings', 'coderembassy-order-guard' ),
		icon: Settings,
	},
	{
		id: 'privacy-logs',
		label: () => __( 'Privacy & Logs', 'coderembassy-order-guard' ),
		icon: Database,
	},
	{
		id: 'help',
		label: () => __( 'Help', 'coderembassy-order-guard' ),
		icon: BadgeHelp,
	},
	{
		id: 'auto-blocklist',
		label: () => __( 'Auto Blocklisting', 'coderembassy-order-guard' ),
		icon: ShieldBan,
		proOnly: true,
	},
	{
		id: 'attack-cleanup',
		label: () => __( 'Attack Cleanup', 'coderembassy-order-guard' ),
		icon: Trash2,
		proOnly: true,
	},
	{
		id: 'pro-license',
		label: () => __( 'Pro License', 'coderembassy-order-guard' ),
		icon: KeyRound,
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
