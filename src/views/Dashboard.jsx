/**
 * Live protection dashboard with visibility-aware polling.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Activity,
	ArrowRight,
	BarChart3,
	CircleCheck,
	Clock3,
	ExternalLink,
	RefreshCw,
	ShieldCheck,
	TriangleAlert,
	X,
} from 'lucide-react';
import { fetchDashboard, friendlyError } from '../api';
import ModeControl from '../components/ModeControl';
import ErrorState from '../components/ErrorState';
import { navigateTo } from '../navigation';

const POLL_DELAY = 30000;
const MAX_BACKOFF = 240000;
const PRO_DISMISS_KEY = 'ceog_pro_cleanup_dismissed_v1';

const readDismissedPro = () => {
	try {
		return window.localStorage.getItem( PRO_DISMISS_KEY ) || '';
	} catch ( error ) {
		void error;
		return '';
	}
};

const eventLabel = ( type ) => {
	switch ( type ) {
		case 'breaker_ip_trip':
			return __( 'Per-IP breaker tripped', 'coderembassy-order-vanguard' );
		case 'breaker_email_trip':
			return __( 'Email breaker tripped', 'coderembassy-order-vanguard' );
		case 'breaker_global_trip':
			return __( 'Global breaker tripped', 'coderembassy-order-vanguard' );
		case 'blocked_add_item':
			return __( 'Add-to-cart blocked', 'coderembassy-order-vanguard' );
		case 'blocked_checkout':
			return __( 'Checkout blocked', 'coderembassy-order-vanguard' );
		case 'blocked_batch_op':
			return __( 'Batch operation blocked', 'coderembassy-order-vanguard' );
		case 'flagged_order':
			return __( 'Order flagged', 'coderembassy-order-vanguard' );
		case 'honeypot_hit':
			return __( 'Honeypot triggered', 'coderembassy-order-vanguard' );
		case 'blocklist_hit':
			return __( 'Blocklist matched', 'coderembassy-order-vanguard' );
		case 'monitor_would_block':
			return __( 'Monitor decision', 'coderembassy-order-vanguard' );
		default:
			return type || __( 'Protection event', 'coderembassy-order-vanguard' );
	}
};

const countFormat = new Intl.NumberFormat();

const formatCount = ( value ) => countFormat.format( Number( value || 0 ) );

const formatRemaining = ( seconds ) => {
	const safeSeconds = Math.max( 0, Number( seconds || 0 ) );
	const minutes = Math.floor( safeSeconds / 60 );
	const remainder = safeSeconds % 60;

	return minutes + ':' + String( remainder ).padStart( 2, '0' );
};

const dayLabel = ( date ) => {
	const parsed = new Date( date + 'T12:00:00' );

	return Number.isNaN( parsed.getTime() )
		? date
		: parsed.toLocaleDateString( undefined, { weekday: 'short' } );
};

const eventTime = ( value ) => {
	const parsed = new Date( String( value || '' ).replace( ' ', 'T' ) );

	return Number.isNaN( parsed.getTime() ) ? value : parsed.toLocaleString();
};

export default function Dashboard( {
	payload,
	modeBusy,
	onModeChange,
	loading: settingsLoading,
	isPro = false,
} ) {
	const [ dashboard, setDashboard ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const [ receivedAt, setReceivedAt ] = useState( 0 );
	const [ clock, setClock ] = useState( Date.now() );
	const [ dismissedPro, setDismissedPro ] = useState( readDismissedPro );

	useEffect( () => {
		let active = true;
		let timer = null;
		let requesting = false;
		let failures = 0;

		const schedule = ( delay ) => {
			window.clearTimeout( timer );
			if ( active && ! document.hidden ) {
				timer = window.setTimeout( load, delay );
			}
		};

		const load = async () => {
			if ( ! active || document.hidden || requesting ) {
				return;
			}

			requesting = true;
			try {
				const response = await fetchDashboard();
				if ( active ) {
					setDashboard( response );
					setReceivedAt( Date.now() );
					setError( '' );
					failures = 0;
				}
			} catch ( requestError ) {
				if ( active ) {
					failures += 1;
					setError( friendlyError( requestError ) );
				}
			} finally {
				requesting = false;
				if ( active ) {
					setLoading( false );
					const delay = failures
						? Math.min( POLL_DELAY * 2 ** ( failures - 1 ), MAX_BACKOFF )
						: POLL_DELAY;
					schedule( delay );
				}
			}
		};

		const onVisibilityChange = () => {
			window.clearTimeout( timer );
			if ( ! document.hidden ) {
				load();
			}
		};

		document.addEventListener( 'visibilitychange', onVisibilityChange );
		load();

		return () => {
			active = false;
			window.clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', onVisibilityChange );
		};
	}, [ reloadKey ] );

	useEffect( () => {
		if ( ! dashboard ) {
			return undefined;
		}

		const interval = window.setInterval( () => setClock( Date.now() ), 1000 );

		return () => window.clearInterval( interval );
	}, [ dashboard ] );

	const elapsed = receivedAt ? Math.floor( ( clock - receivedAt ) / 1000 ) : 0;
	const breaker = useMemo( () => {
		let tier = '';
		let remaining = 0;

		Object.entries( dashboard?.breakers || {} ).forEach( ( [ key, value ] ) => {
			const candidate = Math.max(
				0,
				Number( value?.cooldownSeconds || 0 ) - elapsed
			);
			if ( candidate > remaining ) {
				tier = key;
				remaining = candidate;
			}
		} );

		return { tier, remaining };
	}, [ dashboard, elapsed ] );

	if ( ( loading || settingsLoading ) && ! dashboard ) {
		return (
			<div className="ceog-loading" aria-busy="true">
				<div className="ceog-skeleton ceog-skeleton--hero" />
				<div className="ceog-stat-grid">
					{ [ 1, 2, 3, 4 ].map( ( item ) => (
						<div key={ item } className="ceog-skeleton ceog-skeleton--stat" />
					) ) }
				</div>
			</div>
		);
	}

	if ( error && ! dashboard ) {
		return (
			<ErrorState message={ error } onRetry={ () => setReloadKey( ( key ) => key + 1 ) } />
		);
	}

	const settings = payload?.settings || {};
	const mode = dashboard?.configured_mode || settings.mode || 'monitor';
	const safeMode = Boolean( dashboard?.safe_mode || payload?.meta?.safeMode );
	const effectiveMode = safeMode ? 'monitor' : dashboard?.mode || mode;
	const active = dashboard?.protection_enabled ?? settings.enabled !== false;
	const enforcing = active && effectiveMode === 'enforce';
	const weeklySeries = Array.isArray( dashboard?.weekly_series )
		? dashboard.weekly_series
		: [];
	const maxActivity = Math.max( 1, ...weeklySeries.map( ( day ) => Number( day.count || 0 ) ) );
	const recentEvents = Array.isArray( dashboard?.recent_events )
		? dashboard.recent_events
		: [];
	const proContext = dashboard?.pro_context || {};
	const proSignature = proContext.show
		? String( proContext.blocked_attempts ) + ':' + String( proContext.remaining_orders )
		: '';
	const showPro = Boolean( proContext.show && dismissedPro !== proSignature );

	const dismissPro = () => {
		setDismissedPro( proSignature );
		try {
			window.localStorage.setItem( PRO_DISMISS_KEY, proSignature );
		} catch ( storageError ) {
			void storageError;
		}
	};

	const changeMode = async ( nextMode ) => {
		await onModeChange( nextMode );
		setReloadKey( ( key ) => key + 1 );
	};

	return (
		<div className="ceog-dashboard">
			{ error && (
				<div className="ceog-dashboard-sync-warning" role="status">
					<TriangleAlert size={ 18 } aria-hidden="true" />
					<span>{ error }</span>
					<button
						type="button"
						className="ceog-icon-button"
						onClick={ () => setReloadKey( ( key ) => key + 1 ) }
						aria-label={ __( 'Retry dashboard update', 'coderembassy-order-vanguard' ) }
						title={ __( 'Retry dashboard update', 'coderembassy-order-vanguard' ) }
					>
						<RefreshCw size={ 17 } aria-hidden="true" />
					</button>
				</div>
			) }

			{ safeMode && (
				<div className="ceog-warning" role="status">
					<TriangleAlert size={ 18 } aria-hidden="true" />
					<span>
						{ __( 'Order Vanguard Safe Mode is active - protection is monitoring only.', 'coderembassy-order-vanguard' ) }
					</span>
				</div>
			) }

			<section className="ceog-hero" aria-labelledby="ceog-hero-title">
				<div className="ceog-hero__copy">
					<div className="ceog-hero__badges">
						<span className="ceog-badge ceog-badge--light">{ isPro ? __( 'Pro', 'coderembassy-order-vanguard' ) : __( 'Free', 'coderembassy-order-vanguard' ) }</span>
						<span className="ceog-hero__status">
							<CircleCheck size={ 15 } aria-hidden="true" />
							{ ! active
								? __( 'Protection disabled', 'coderembassy-order-vanguard' )
								: enforcing
									? __( 'Protection active', 'coderembassy-order-vanguard' )
									: __( 'Monitoring traffic', 'coderembassy-order-vanguard' ) }
						</span>
					</div>
					<h2 id="ceog-hero-title">{ __( 'CoderEmbassy Order Vanguard', 'coderembassy-order-vanguard' ) }</h2>
					<p>{ __( 'API-level protection against card testing, bot orders, and fake checkouts.', 'coderembassy-order-vanguard' ) }</p>
					<button type="button" className="ceog-button ceog-button--hero" onClick={ () => navigateTo( 'activity-log' ) }>
						<Activity size={ 16 } aria-hidden="true" />
						{ __( 'View activity log', 'coderembassy-order-vanguard' ) }
						<ArrowRight size={ 16 } aria-hidden="true" />
					</button>
				</div>

				<div className="ceog-hero__mode">
					<span>{ __( 'Protection mode', 'coderembassy-order-vanguard' ) }</span>
					<strong>{ enforcing ? __( 'Enforcing', 'coderembassy-order-vanguard' ) : __( 'Monitoring', 'coderembassy-order-vanguard' ) }</strong>
					<ModeControl mode={ mode } onChange={ changeMode } busy={ modeBusy } />
				</div>
			</section>

			<section className="ceog-stat-grid" aria-label={ __( 'Protection summary', 'coderembassy-order-vanguard' ) }>
				<div className="ceog-stat ceog-stat--green">
					<ShieldCheck size={ 19 } aria-hidden="true" />
					<strong>{ formatCount( dashboard?.blocked_today ) }</strong>
					<span>{ __( 'Blocked today', 'coderembassy-order-vanguard' ) }</span>
				</div>
				<div className="ceog-stat ceog-stat--amber">
					<Activity size={ 19 } aria-hidden="true" />
					<strong>{ formatCount( dashboard?.suspicious_7d ) }</strong>
					<span>{ __( 'Suspicious (7d)', 'coderembassy-order-vanguard' ) }</span>
				</div>
				<div className="ceog-stat ceog-stat--purple">
					<CircleCheck size={ 19 } aria-hidden="true" />
					<strong>{ enforcing ? __( 'Enforce', 'coderembassy-order-vanguard' ) : __( 'Monitor', 'coderembassy-order-vanguard' ) }</strong>
					<span>{ __( 'Protection mode', 'coderembassy-order-vanguard' ) }</span>
				</div>
				<div className={ 'ceog-stat ' + ( breaker.remaining > 0 ? 'ceog-stat--danger' : 'ceog-stat--blue' ) }>
					<Clock3 size={ 19 } aria-hidden="true" />
					<strong>{ breaker.remaining > 0 ? formatRemaining( breaker.remaining ) : __( 'Ready', 'coderembassy-order-vanguard' ) }</strong>
					<span>{ breaker.remaining > 0 && enforcing ? __( 'Checkout paused', 'coderembassy-order-vanguard' ) : __( 'Circuit breakers', 'coderembassy-order-vanguard' ) }</span>
				</div>
			</section>

			<section className="ceog-quickstart">
				<div>
					<span className="ceog-section-kicker">{ __( 'Monitor first', 'coderembassy-order-vanguard' ) }</span>
					<h2>{ __( 'Start with a clear baseline', 'coderembassy-order-vanguard' ) }</h2>
					<p>{ __( 'Keep Monitor mode on while Order Vanguard records traffic. Switch to Enforce after the activity log matches what you expect.', 'coderembassy-order-vanguard' ) }</p>
				</div>
				<button type="button" className="ceog-button" onClick={ () => navigateTo( 'settings' ) }>
					{ __( 'Review settings', 'coderembassy-order-vanguard' ) }
					<ArrowRight size={ 16 } aria-hidden="true" />
				</button>
			</section>

			<div className="ceog-dashboard-grid">
				<section className="ceog-dashboard-panel" aria-labelledby="ceog-weekly-title">
					<div className="ceog-dashboard-panel__header">
						<div>
							<BarChart3 size={ 19 } aria-hidden="true" />
							<h2 id="ceog-weekly-title">{ __( 'Threat activity this week', 'coderembassy-order-vanguard' ) }</h2>
						</div>
						<span>{ formatCount( dashboard?.suspicious_7d ) }</span>
					</div>
					<div className="ceog-weekly-chart">
						{ weeklySeries.map( ( day ) => (
							<div className="ceog-weekly-chart__day" key={ day.date }>
								<span className="ceog-weekly-chart__value">{ formatCount( day.count ) }</span>
								<div className="ceog-weekly-chart__track">
									<span style={ { height: Number( day.count ) > 0 ? Math.max( 8, ( Number( day.count ) / maxActivity ) * 100 ) + '%' : '0%' } } />
								</div>
								<strong>{ dayLabel( day.date ) }</strong>
							</div>
						) ) }
					</div>
				</section>

				<section className="ceog-dashboard-panel" aria-labelledby="ceog-recent-title">
					<div className="ceog-dashboard-panel__header">
						<div>
							<Activity size={ 19 } aria-hidden="true" />
							<h2 id="ceog-recent-title">{ __( 'Recent protection events', 'coderembassy-order-vanguard' ) }</h2>
						</div>
						<button type="button" className="ceog-button ceog-button--quiet" onClick={ () => navigateTo( 'activity-log' ) }>
							{ __( 'View all', 'coderembassy-order-vanguard' ) }
							<ArrowRight size={ 15 } aria-hidden="true" />
						</button>
					</div>
					{ recentEvents.length ? (
						<ul className="ceog-recent-events">
							{ recentEvents.map( ( event ) => (
								<li key={ event.id }>
									<span className={ 'ceog-event-dot ceog-event-dot--' + ( event.mode === 'enforce' ? 'enforce' : 'monitor' ) } />
									<div>
										<strong>{ eventLabel( event.event_type ) }</strong>
										<small>{ eventTime( event.event_time ) }</small>
									</div>
									{ event.order_url && (
										<a href={ event.order_url } aria-label={ __( 'Open order', 'coderembassy-order-vanguard' ) } title={ __( 'Open order', 'coderembassy-order-vanguard' ) }>
											<ExternalLink size={ 16 } aria-hidden="true" />
										</a>
									) }
								</li>
							) ) }
						</ul>
					) : (
						<div className="ceog-dashboard-empty">
							<ShieldCheck size={ 22 } aria-hidden="true" />
							<span>{ __( 'No protection events yet', 'coderembassy-order-vanguard' ) }</span>
						</div>
					) }
				</section>
			</div>

			{ showPro && (
				<section className="ceog-pro-context" aria-labelledby="ceog-pro-context-title">
					<div>
						<span className="ceog-badge">{ __( 'Pro', 'coderembassy-order-vanguard' ) }</span>
						<h2 id="ceog-pro-context-title">{ __( 'Attack cleanup is available', 'coderembassy-order-vanguard' ) }</h2>
						<p>
							{ sprintf(
								__( 'Order Vanguard blocked %1$s attempts. %2$s failed or junk orders remain - Pro can clean these up automatically.', 'coderembassy-order-vanguard' ),
								formatCount( proContext.blocked_attempts ),
								formatCount( proContext.remaining_orders )
							) }
						</p>
					</div>
					<button type="button" className="ceog-icon-button" onClick={ dismissPro } aria-label={ __( 'Dismiss cleanup suggestion', 'coderembassy-order-vanguard' ) } title={ __( 'Dismiss cleanup suggestion', 'coderembassy-order-vanguard' ) }>
						<X size={ 18 } aria-hidden="true" />
					</button>
				</section>
			) }
		</div>
	);
}
