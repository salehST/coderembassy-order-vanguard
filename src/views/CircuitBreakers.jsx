/**
 * Tiered failed-order circuit breaker settings and live state.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	CircleCheck,
	Clock3,
	Globe2,
	Mail,
	Network,
	RefreshCw,
	Save,
	ShieldCheck,
	TriangleAlert,
} from 'lucide-react';
import { fetchBreakers, friendlyError } from '../api';
import ToggleField from '../components/ToggleField';

const makeDraft = ( settings = {} ) => ( {
	breaker_ip_enabled: settings.breaker_ip_enabled !== false,
	breaker_ip_threshold: String( settings.breaker_ip_threshold || 5 ),
	breaker_ip_window: String( settings.breaker_ip_window || 300 ),
	breaker_ip_block: String( settings.breaker_ip_block || 600 ),
	breaker_email_enabled: settings.breaker_email_enabled !== false,
	breaker_email_threshold: String( settings.breaker_email_threshold || 3 ),
	breaker_email_window: String( settings.breaker_email_window || 600 ),
	breaker_email_block: String( settings.breaker_email_block || 900 ),
	breaker_global_enabled: settings.breaker_global_enabled !== false,
	breaker_global_threshold: String( settings.breaker_global_threshold || 20 ),
	breaker_global_window: String( settings.breaker_global_window || 300 ),
	breaker_global_cooldown: String(
		settings.breaker_global_cooldown || 120
	),
} );

const TIER_CONFIG = {
	ip: {
		Icon: Network,
		title: () => __( 'Per-IP breaker', 'coderembassy-order-guard' ),
		description: () =>
			__(
				'Stops repeated failed orders from one IPv4 address or IPv6 /64 network.',
				'coderembassy-order-guard'
			),
		enabled: 'breaker_ip_enabled',
		threshold: 'breaker_ip_threshold',
		window: 'breaker_ip_window',
		duration: 'breaker_ip_block',
		durationLabel: () =>
			__( 'Block duration (seconds)', 'coderembassy-order-guard' ),
		note: () =>
			__(
				'Shared mobile, office, and CGNAT connections can represent several customers. Keep this block short.',
				'coderembassy-order-guard'
			),
	},
	email: {
		Icon: Mail,
		title: () => __( 'Per-email breaker', 'coderembassy-order-guard' ),
		description: () =>
			__(
				'Links rotating IPs when repeated failures use the same billing email.',
				'coderembassy-order-guard'
			),
		enabled: 'breaker_email_enabled',
		threshold: 'breaker_email_threshold',
		window: 'breaker_email_window',
		duration: 'breaker_email_block',
		durationLabel: () =>
			__( 'Block duration (seconds)', 'coderembassy-order-guard' ),
		note: () =>
			__(
				'A shared household or team billing email may affect more than one shopper. The default requires three failures.',
				'coderembassy-order-guard'
			),
	},
	global: {
		Icon: Globe2,
		title: () => __( 'Global breaker', 'coderembassy-order-guard' ),
		description: () =>
			__(
				'Pauses non-whitelisted checkout when failures arrive across the whole store.',
				'coderembassy-order-guard'
			),
		enabled: 'breaker_global_enabled',
		threshold: 'breaker_global_threshold',
		window: 'breaker_global_window',
		duration: 'breaker_global_cooldown',
		durationLabel: () =>
			__( 'Cooldown (seconds)', 'coderembassy-order-guard' ),
		note: () =>
			__(
				'This temporarily pauses all non-whitelisted checkout. A short cooldown is the primary defense against distributed attacks.',
				'coderembassy-order-guard'
			),
	},
};

const formatRemaining = ( seconds ) => {
	const value = Math.max( 0, Number( seconds ) || 0 );
	const minutes = Math.floor( value / 60 );
	const remainder = value % 60;

	return minutes > 0
		? sprintf(
			/* translators: 1: minutes, 2: seconds */
			__( '%1$dm %2$ds remaining', 'coderembassy-order-guard' ),
			minutes,
			remainder
		)
		: sprintf(
			/* translators: %d: seconds */
			__( '%d seconds remaining', 'coderembassy-order-guard' ),
			remainder
		);
};

function TierStatus( { tier } ) {
	if ( ! tier?.enabled ) {
		return (
			<div className="ceog-breaker-live ceog-breaker-live--muted">
				<span>{ __( 'Disabled', 'coderembassy-order-guard' ) }</span>
				<small>{ __( 'Protection inactive', 'coderembassy-order-guard' ) }</small>
			</div>
		);
	}

	if ( tier.state === 'cooling_down' ) {
		return (
			<div
				className={
					tier.blocking
						? 'ceog-breaker-live ceog-breaker-live--danger'
						: 'ceog-breaker-live ceog-breaker-live--warning'
				}
			>
				<Clock3 size={ 17 } aria-hidden="true" />
				<div>
					<span>{ __( 'Cooling down', 'coderembassy-order-guard' ) }</span>
					<small>{ formatRemaining( tier.cooldownSeconds ) }</small>
				</div>
				<strong>
					{ tier.blocking
						? __( 'Blocking', 'coderembassy-order-guard' )
						: __( 'Logging', 'coderembassy-order-guard' ) }
				</strong>
			</div>
		);
	}

	return (
		<div className="ceog-breaker-live ceog-breaker-live--ready">
			<CircleCheck size={ 17 } aria-hidden="true" />
			<div>
				<span>{ __( 'Ready', 'coderembassy-order-guard' ) }</span>
				<small>{ __( 'Protection active', 'coderembassy-order-guard' ) }</small>
			</div>
		</div>
	);
}

function TierPanel( { name, draft, status, onUpdate } ) {
	const config = TIER_CONFIG[ name ];
	const Icon = config.Icon;
	const enabled = Boolean( draft[ config.enabled ] );

	return (
		<section
			className={
				'ceog-settings-panel ceog-breaker-panel ceog-breaker-panel--' +
				name
			}
		>
			<div className="ceog-settings-panel__heading">
				<Icon size={ 20 } aria-hidden="true" />
				<div>
					<div className="ceog-breaker-title-row">
						<h3>{ config.title() }</h3>
						{ name === 'global' && (
							<span className="ceog-badge ceog-badge--primary">
								{ __( 'Distributed defense', 'coderembassy-order-guard' ) }
							</span>
						) }
					</div>
					<p>{ config.description() }</p>
				</div>
			</div>

			<TierStatus tier={ status } />

			<ToggleField
				id={ 'ceog-' + name + '-breaker-enabled' }
				label={ __( 'Enable this breaker', 'coderembassy-order-guard' ) }
				description={ __(
					'Failed orders feed this sliding window in both Monitor and Enforce modes.',
					'coderembassy-order-guard'
				) }
				checked={ enabled }
				onChange={ ( value ) => onUpdate( config.enabled, value ) }
			/>

			<div className="ceog-breaker-fields">
				<label className="ceog-field" htmlFor={ 'ceog-' + name + '-threshold' }>
					<span>{ __( 'Failed orders', 'coderembassy-order-guard' ) }</span>
					<input
						id={ 'ceog-' + name + '-threshold' }
						type="number"
						min="1"
						max={ name === 'global' ? '10000' : '1000' }
						value={ draft[ config.threshold ] }
						onChange={ ( event ) =>
							onUpdate( config.threshold, event.target.value )
						}
						disabled={ ! enabled }
					/>
				</label>
				<label className="ceog-field" htmlFor={ 'ceog-' + name + '-window' }>
					<span>{ __( 'Window (seconds)', 'coderembassy-order-guard' ) }</span>
					<input
						id={ 'ceog-' + name + '-window' }
						type="number"
						min="30"
						max="86400"
						value={ draft[ config.window ] }
						onChange={ ( event ) =>
							onUpdate( config.window, event.target.value )
						}
						disabled={ ! enabled }
					/>
				</label>
				<label className="ceog-field" htmlFor={ 'ceog-' + name + '-duration' }>
					<span>{ config.durationLabel() }</span>
					<input
						id={ 'ceog-' + name + '-duration' }
						type="number"
						min="30"
						max="86400"
						value={ draft[ config.duration ] }
						onChange={ ( event ) =>
							onUpdate( config.duration, event.target.value )
						}
						disabled={ ! enabled }
					/>
				</label>
			</div>

			<div className="ceog-breaker-note">
				<TriangleAlert size={ 17 } aria-hidden="true" />
				<p>{ config.note() }</p>
			</div>
		</section>
	);
}

export default function CircuitBreakers( {
	payload,
	loading,
	settingsBusy,
	onSave,
} ) {
	const [ draft, setDraft ] = useState( makeDraft( payload?.settings ) );
	const [ status, setStatus ] = useState( null );
	const [ statusLoading, setStatusLoading ] = useState( true );
	const [ statusError, setStatusError ] = useState( '' );
	const [ saved, setSaved ] = useState( false );

	const loadStatus = useCallback( async ( showLoader = false ) => {
		if ( showLoader ) {
			setStatusLoading( true );
		}
		setStatusError( '' );

		try {
			setStatus( await fetchBreakers() );
		} catch ( error ) {
			setStatusError( friendlyError( error ) );
		} finally {
			setStatusLoading( false );
		}
	}, [] );

	useEffect( () => {
		setDraft( makeDraft( payload?.settings ) );
		setSaved( false );
	}, [ payload?.settings ] );

	useEffect( () => {
		loadStatus( true );
	}, [ loadStatus ] );

	useEffect( () => {
		const polling = window.setInterval( () => {
			if ( ! document.hidden ) {
				loadStatus();
			}
		}, 15000 );

		return () => window.clearInterval( polling );
	}, [ loadStatus ] );

	useEffect( () => {
		const countdown = window.setInterval( () => {
			setStatus( ( current ) => {
				if ( ! current?.tiers ) {
					return current;
				}

				const tiers = Object.fromEntries(
					Object.entries( current.tiers ).map( ( [ key, tier ] ) => {
						const remaining = Math.max(
							0,
							Number( tier.cooldownSeconds || 0 ) - 1
						);

						return [
							key,
							{
								...tier,
								cooldownSeconds: remaining,
								state:
									remaining > 0
										? tier.state
										: tier.enabled
											? 'ready'
											: 'disabled',
								blocking: remaining > 0 && tier.blocking,
								activeEntities:
									remaining > 0 ? tier.activeEntities : 0,
							},
						];
					} )
				);

				return { ...current, tiers };
			} );
		}, 1000 );

		return () => window.clearInterval( countdown );
	}, [] );

	const update = ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
		setSaved( false );
	};

	const save = async () => {
		const patch = Object.fromEntries(
			Object.entries( draft ).map( ( [ key, value ] ) => [
				key,
				typeof value === 'boolean' ? value : Number( value ),
			] )
		);
		const ok = await onSave( patch );
		setSaved( Boolean( ok ) );
		if ( ok ) {
			loadStatus();
		}
	};

	if ( loading || ! payload ) {
		return (
			<div className="ceog-loading" aria-busy="true">
				<div className="ceog-skeleton ceog-skeleton--store-api" />
				<div className="ceog-breaker-grid">
					<div className="ceog-skeleton ceog-skeleton--stat" />
					<div className="ceog-skeleton ceog-skeleton--stat" />
					<div className="ceog-skeleton ceog-skeleton--stat" />
				</div>
			</div>
		);
	}

	const activeCount = status?.tiers
		? Object.values( status.tiers ).filter(
				( tier ) => tier.state === 'cooling_down'
			).length
		: 0;

	return (
		<div className="ceog-page ceog-breakers-page">
			<header className="ceog-page__header">
				<div>
					<p className="ceog-section-kicker">
						{ __( 'Failed-order protection', 'coderembassy-order-guard' ) }
					</p>
					<h2>{ __( 'Circuit Breakers', 'coderembassy-order-guard' ) }</h2>
					<p>
						{ __(
							'Three short sliding windows stop repeated payment failures with the smallest practical checkout impact.',
							'coderembassy-order-guard'
						) }
					</p>
				</div>
				<div className="ceog-breaker-header-status">
					<span
						className={
							activeCount > 0
								? 'ceog-status ceog-status--warning'
								: 'ceog-status ceog-status--success'
						}
					>
						<ShieldCheck size={ 15 } aria-hidden="true" />
						{ activeCount > 0
							? sprintf(
								/* translators: %d: active breaker tiers */
								__( '%d cooling down', 'coderembassy-order-guard' ),
								activeCount
							)
							: __( 'All ready', 'coderembassy-order-guard' ) }
					</span>
					<button
						type="button"
						className="ceog-icon-button"
						onClick={ () => loadStatus( true ) }
						disabled={ statusLoading }
						aria-label={ __( 'Refresh breaker status', 'coderembassy-order-guard' ) }
						title={ __( 'Refresh breaker status', 'coderembassy-order-guard' ) }
					>
						<RefreshCw size={ 17 } aria-hidden="true" />
					</button>
				</div>
			</header>

			{ statusError && (
				<div className="ceog-warning" role="alert">
					<TriangleAlert size={ 18 } aria-hidden="true" />
					<span>{ statusError }</span>
				</div>
			) }

			{ payload.meta?.safeMode && (
				<div className="ceog-warning" role="status">
					<TriangleAlert size={ 18 } aria-hidden="true" />
					<span>
						{ __(
							'Safe Mode keeps breaker counters and logging active while all checkout blocking remains suspended.',
							'coderembassy-order-guard'
						) }
					</span>
				</div>
			) }

			<div className="ceog-breaker-grid">
				{ [ 'ip', 'email', 'global' ].map( ( name ) => (
					<TierPanel
						key={ name }
						name={ name }
						draft={ draft }
						status={ status?.tiers?.[ name ] }
						onUpdate={ update }
					/>
				) ) }
			</div>

			<div className="ceog-save-bar">
				{ saved && (
					<span className="ceog-saved" role="status">
						<CircleCheck size={ 15 } aria-hidden="true" />
						{ __( 'Circuit breaker settings saved', 'coderembassy-order-guard' ) }
					</span>
				) }
				<button
					type="button"
					className="ceog-button ceog-button--primary"
					onClick={ save }
					disabled={ settingsBusy }
				>
					<Save size={ 16 } aria-hidden="true" />
					{ settingsBusy
						? __( 'Saving...', 'coderembassy-order-guard' )
						: __( 'Save breaker settings', 'coderembassy-order-guard' ) }
				</button>
			</div>
		</div>
	);
}
