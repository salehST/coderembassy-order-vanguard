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
		label: () => __( 'Dashboard', 'coderembassy-order-vanguard' ),
		icon: LayoutDashboard,
	},
	{
		id: 'activity-log',
		label: () => __( 'Activity Log', 'coderembassy-order-vanguard' ),
		icon: Activity,
	},
	{
		id: 'history-reporting',
		label: () => __( 'History & Reports', 'coderembassy-order-vanguard' ),
		icon: History,
		proOnly: true,
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
		id: 'turnstile',
		label: () => __( 'Turnstile', 'coderembassy-order-vanguard' ),
		icon: Cloud,
		proOnly: true,
	},
	{
		id: 'alerts',
		label: () => __( 'Alerts', 'coderembassy-order-vanguard' ),
		icon: BellRing,
		proOnly: true,
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
	{
		id: 'auto-blocklist',
		label: () => __( 'Auto Blocklisting', 'coderembassy-order-vanguard' ),
		icon: ShieldBan,
		proOnly: true,
	},
	{
		id: 'attack-cleanup',
		label: () => __( 'Attack Cleanup', 'coderembassy-order-vanguard' ),
		icon: Trash2,
		proOnly: true,
	},
	{
		id: 'pro-license',
		label: () => __( 'Pro License', 'coderembassy-order-vanguard' ),
		icon: KeyRound,
		proOnly: true,
	},
];

export function readRoute( isPro = false ) {
	const requested = window.location.hash
		.replace( /^#\/?/, '' )
		.split( '?' )[ 0 ];
	const item = NAV_ITEMS.find( ( candidate ) => candidate.id === requested );

	return item && ( ! item.proOnly || isPro ) ? requested : DEFAULT_ROUTE;
}

export function navigateTo( route ) {
	window.location.hash = '#/' + route;
}

export function getNavItem( route ) {
	return NAV_ITEMS.find( ( item ) => item.id === route ) || NAV_ITEMS[ 0 ];
}
