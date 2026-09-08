/**
 * Purposeful empty states for later feature phases.
 */
import { __ } from '@wordpress/i18n';
import {
	Activity,
	BadgeHelp,
	Blocks,
	Database,
	KeyRound,
	ListFilter,
	ShieldAlert,
} from 'lucide-react';

const CONTENT = {
	'activity-log': {
		icon: Activity,
		title: () => __( 'No protection events yet', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'Monitor and blocked events will appear here with server-side filters and pagination.',
				'coderembassy-order-vanguard'
			),
	},
	'circuit-breakers': {
		icon: ShieldAlert,
		title: () => __( 'Circuit breakers are standing by', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'Per-IP, per-email, and global checkout protection will report their live state here.',
				'coderembassy-order-vanguard'
			),
	},
	'store-api': {
		icon: Blocks,
		title: () => __( 'Store API Guard', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'Store API rate limits, batch inspection, and advanced emergency controls will appear here.',
				'coderembassy-order-vanguard'
			),
	},
	lists: {
		icon: ListFilter,
		title: () => __( 'No list entries yet', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'Blocklist and whitelist entries will be managed from this workspace.',
				'coderembassy-order-vanguard'
			),
	},
	'privacy-logs': {
		icon: Database,
		title: () => __( 'Privacy-first logging', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'IP anonymization, trusted proxy handling, retention, and uninstall controls will live here.',
				'coderembassy-order-vanguard'
			),
	},
	help: {
		icon: BadgeHelp,
		title: () => __( 'Order Vanguard help', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'Monitoring guidance and checkout compatibility notes will be available here.',
				'coderembassy-order-vanguard'
			),
	},
	'pro-license': {
		icon: KeyRound,
		title: () => __( 'Order Vanguard Pro', 'coderembassy-order-vanguard' ),
		description: () =>
			__(
				'Attack cleanup, automated blocking, longer history, and advanced alerts are planned for Pro.',
				'coderembassy-order-vanguard'
			),
	},
};

export default function Placeholder( { route, boot = {} } ) {
	const definition = CONTENT[ route ] || CONTENT.help;
	const Icon = definition.icon;
	const proActive = route === 'pro-license' && Boolean( boot.isPro );

	return (
		<div className="ceog-page">
			<section className="ceog-empty">
				<div className="ceog-empty__icon">
					<Icon size={ 25 } aria-hidden="true" />
				</div>
				<h2>{ proActive ? __( 'Order Vanguard Pro is active', 'coderembassy-order-vanguard' ) : definition.title() }</h2>
				<p>
					{ proActive
						? __( 'Attack Cleanup and Pro automation tools are ready from the Order Vanguard navigation.', 'coderembassy-order-vanguard' )
						: definition.description() }
				</p>
				{ proActive && boot.proUrl && (
					<a className="ceog-button ceog-button--primary" href={ boot.proUrl }>
						{ __( 'Open Attack Cleanup', 'coderembassy-order-vanguard' ) }
					</a>
				) }
				<span className="ceog-badge">
					{ proActive ? __( 'Active', 'coderembassy-order-vanguard' ) : __( 'Workspace ready', 'coderembassy-order-vanguard' ) }
				</span>
			</section>
		</div>
	);
}
