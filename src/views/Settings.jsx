/**
 * Settings and safety controls.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Bell,
	CircleCheck,
	LockKeyhole,
	Save,
	Shield,
	ShoppingCart,
	TriangleAlert,
} from 'lucide-react';
import ModeControl from '../components/ModeControl';
import ToggleField from '../components/ToggleField';

const makeDraft = ( settings = {} ) => ( {
	enabled: settings.enabled !== false,
	honeypot_enabled: settings.honeypot_enabled !== false,
	unknown_origin_action: settings.unknown_origin_action || 'flag',
	unknown_origin_onhold: Boolean( settings.unknown_origin_onhold ),
	alert_email_enabled: settings.alert_email_enabled !== false,
	alert_email: settings.alert_email || '',
	strict_session: Boolean( settings.strict_session ),
	emergency_lockdown: Boolean( settings.emergency_lockdown ),
} );

export default function SettingsView( {
	payload,
	modeBusy,
	settingsBusy,
	onModeChange,
	onSave,
} ) {
	const settings = payload?.settings || {};
	const meta = payload?.meta || {};
	const [ draft, setDraft ] = useState( makeDraft( settings ) );
	const [ strictAcknowledged, setStrictAcknowledged ] = useState( false );
	const [ emergencyAcknowledged, setEmergencyAcknowledged ] =
		useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		setDraft( makeDraft( payload?.settings ) );
		setStrictAcknowledged( false );
		setEmergencyAcknowledged( false );
		setSaved( false );
	}, [ payload?.settings ] );

	const update = ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
		setSaved( false );
	};

	const save = async () => {
		setSaved( Boolean( await onSave( draft ) ) );
	};

	return (
		<div className="ceog-page ceog-settings-stack">
			<header className="ceog-page__header">
				<div>
					<p className="ceog-section-kicker">
						{ __( 'General', 'coderembassy-order-guard' ) }
					</p>
					<h2>{ __( 'Protection settings', 'coderembassy-order-guard' ) }</h2>
					<p>
						{ __(
							'Choose what Order Guard records and how it responds to suspicious checkout traffic.',
							'coderembassy-order-guard'
						) }
					</p>
				</div>
				<span
					className={
						meta.safeMode
							? 'ceog-status ceog-status--warning'
							: 'ceog-status ceog-status--success'
					}
				>
					<Shield size={ 15 } aria-hidden="true" />
					{ meta.safeMode
						? __( 'Safe Mode', 'coderembassy-order-guard' )
						: __( 'Ready', 'coderembassy-order-guard' ) }
				</span>
			</header>

			{ meta.safeMode && (
				<div className="ceog-warning" role="status">
					<TriangleAlert size={ 18 } aria-hidden="true" />
					<span>
						{ __(
							'Safe Mode overrides these settings and keeps all protection in Monitor mode. Settings remain available and logging stays active.',
							'coderembassy-order-guard'
						) }
					</span>
				</div>
			) }

			<div className="ceog-settings-grid">
				<section className="ceog-settings-panel">
					<div className="ceog-settings-panel__heading">
						<Shield size={ 20 } aria-hidden="true" />
						<div>
							<h3>{ __( 'Global protection', 'coderembassy-order-guard' ) }</h3>
							<p>
								{ __(
									'Disabling this keeps your configuration while suspending all blocking.',
									'coderembassy-order-guard'
								) }
							</p>
						</div>
					</div>
					<ToggleField
						id="ceog-enabled"
						label={ __( 'Enable Order Guard', 'coderembassy-order-guard' ) }
						description={
							draft.enabled
								? __( 'Protection is enabled', 'coderembassy-order-guard' )
								: __( 'Protection is disabled', 'coderembassy-order-guard' )
						}
						checked={ draft.enabled }
						onChange={ ( value ) => update( 'enabled', value ) }
					/>
				</section>

				<section className="ceog-settings-panel">
					<div className="ceog-settings-panel__heading">
						<CircleCheck size={ 20 } aria-hidden="true" />
						<div>
							<h3>{ __( 'Protection mode', 'coderembassy-order-guard' ) }</h3>
							<p>
								{ __(
									'Monitor records decisions. Enforce applies them at checkout.',
									'coderembassy-order-guard'
								) }
							</p>
						</div>
					</div>
					<ModeControl
						mode={ settings.mode || 'monitor' }
						onChange={ onModeChange }
						busy={ modeBusy }
					/>
					<dl className="ceog-settings-meta">
						<div>
							<dt>{ __( 'Effective mode', 'coderembassy-order-guard' ) }</dt>
							<dd>
								{ meta.enforcing
									? __( 'Enforcing', 'coderembassy-order-guard' )
									: __( 'Monitoring', 'coderembassy-order-guard' ) }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Plugin version', 'coderembassy-order-guard' ) }</dt>
							<dd>{ meta.version || '1.0.3' }</dd>
						</div>
					</dl>
				</section>

				<section className="ceog-settings-panel">
					<div className="ceog-settings-panel__heading">
						<ShoppingCart size={ 20 } aria-hidden="true" />
						<div>
							<h3>{ __( 'Order rules', 'coderembassy-order-guard' ) }</h3>
							<p>
								{ __(
									'Collect low-friction signals without changing legitimate checkout fields.',
									'coderembassy-order-guard'
								) }
							</p>
						</div>
					</div>
					<ToggleField
						id="ceog-honeypot"
						label={ __( 'Classic checkout honeypot', 'coderembassy-order-guard' ) }
						description={ __(
							'Adds an invisible field that human shoppers never interact with.',
							'coderembassy-order-guard'
						) }
						checked={ draft.honeypot_enabled }
						onChange={ ( value ) => update( 'honeypot_enabled', value ) }
					/>
					<label className="ceog-field" htmlFor="ceog-unknown-origin">
						<span>{ __( 'Unknown-origin orders', 'coderembassy-order-guard' ) }</span>
						<select
							id="ceog-unknown-origin"
							value={ draft.unknown_origin_action }
							onChange={ ( event ) =>
								update( 'unknown_origin_action', event.target.value )
							}
						>
							<option value="flag">
								{ __( 'Flag for review', 'coderembassy-order-guard' ) }
							</option>
							<option value="off">
								{ __( 'Do not flag', 'coderembassy-order-guard' ) }
							</option>
						</select>
					</label>
					<ToggleField
						id="ceog-unknown-origin-onhold"
						label={ __( 'Place unpaid flagged orders on hold', 'coderembassy-order-guard' ) }
						description={ __(
							'Paid orders are never moved; this rule does not block checkout.',
							'coderembassy-order-guard'
						) }
						checked={ draft.unknown_origin_onhold }
						onChange={ ( value ) => update( 'unknown_origin_onhold', value ) }
						disabled={ draft.unknown_origin_action === 'off' }
					/>
				</section>

				<section className="ceog-settings-panel">
					<div className="ceog-settings-panel__heading">
						<Bell size={ 20 } aria-hidden="true" />
						<div>
							<h3>{ __( 'Alert email', 'coderembassy-order-guard' ) }</h3>
							<p>
								{ __(
									'Choose where important protection notices will be delivered.',
									'coderembassy-order-guard'
								) }
							</p>
						</div>
					</div>
					<ToggleField
						id="ceog-alerts"
						label={ __( 'Enable email alerts', 'coderembassy-order-guard' ) }
						description={ __(
							'Uses the WordPress administrator email when the field below is empty.',
							'coderembassy-order-guard'
						) }
						checked={ draft.alert_email_enabled }
						onChange={ ( value ) => update( 'alert_email_enabled', value ) }
					/>
					<label className="ceog-field" htmlFor="ceog-alert-email">
						<span>{ __( 'Recipient', 'coderembassy-order-guard' ) }</span>
						<input
							id="ceog-alert-email"
							type="email"
							value={ draft.alert_email }
							placeholder={ __( 'WordPress administrator email', 'coderembassy-order-guard' ) }
							onChange={ ( event ) => update( 'alert_email', event.target.value ) }
							disabled={ ! draft.alert_email_enabled }
						/>
					</label>
				</section>

				<section className="ceog-settings-panel ceog-settings-panel--wide">
					<div className="ceog-settings-panel__heading">
						<LockKeyhole size={ 20 } aria-hidden="true" />
						<div>
							<h3>{ __( 'Advanced Store API controls', 'coderembassy-order-guard' ) }</h3>
							<p>
								{ __(
									'These opt-in controls can affect headless, app, block, and express checkout flows.',
									'coderembassy-order-guard'
								) }
							</p>
						</div>
					</div>

					<div className="ceog-environment-row">
						<div>
							<span>{ __( 'WooCommerce Checkout', 'coderembassy-order-guard' ) }</span>
							<strong>
								{ meta.checkoutBlockDetected
									? __( 'Checkout block detected', 'coderembassy-order-guard' )
									: __( 'Classic checkout detected', 'coderembassy-order-guard' ) }
							</strong>
						</div>
						<div>
							<span>{ __( 'Native checkout limiter', 'coderembassy-order-guard' ) }</span>
							<strong>
								{ meta.nativeRateLimitEnabled
									? __( 'Enabled in WooCommerce', 'coderembassy-order-guard' )
									: __( 'Not enabled', 'coderembassy-order-guard' ) }
							</strong>
						</div>
					</div>

					<div className="ceog-advanced-setting">
						<ToggleField
							id="ceog-strict-session"
							label={ __( 'Strict Session Requirement', 'coderembassy-order-guard' ) }
							description={ __(
								'Requires an existing WooCommerce session for Store API cart mutations.',
								'coderembassy-order-guard'
							) }
							checked={ draft.strict_session }
							onChange={ ( value ) => update( 'strict_session', value ) }
							disabled={ ! draft.strict_session && ! strictAcknowledged }
							tone="warning"
						/>
						<div className="ceog-risk ceog-risk--warning">
							<TriangleAlert size={ 18 } aria-hidden="true" />
							<p>
								{ __(
									'This may break headless, custom, mobile-app, and some express checkout flows. Use Monitor Mode first.',
									'coderembassy-order-guard'
								) }
							</p>
						</div>
						<label className="ceog-acknowledgement">
							<input
								type="checkbox"
								checked={ strictAcknowledged }
								onChange={ ( event ) =>
									setStrictAcknowledged( event.target.checked )
								}
							/>
							<span>
								{ __( 'I understand the risks', 'coderembassy-order-guard' ) }
							</span>
						</label>
					</div>

					<div className="ceog-advanced-setting">
						<ToggleField
							id="ceog-emergency-lockdown"
							label={ __( 'Emergency Store API Checkout Lockdown', 'coderembassy-order-guard' ) }
							description={ __(
								'Returns 404 for Store API checkout requests, including checkout operations inside batch requests.',
								'coderembassy-order-guard'
							) }
							checked={ draft.emergency_lockdown }
							onChange={ ( value ) => update( 'emergency_lockdown', value ) }
							disabled={
								meta.checkoutBlockDetected ||
								( ! draft.emergency_lockdown && ! emergencyAcknowledged )
							}
							tone="danger"
						/>
						<div
							className="ceog-risk ceog-risk--danger"
							role={ draft.emergency_lockdown ? 'alert' : undefined }
						>
							<TriangleAlert size={ 18 } aria-hidden="true" />
							<p>
								{ meta.checkoutBlockDetected
									? __(
										'Emergency Lockdown cannot be enabled while the WooCommerce Checkout block is active.',
										'coderembassy-order-guard'
									)
									: __(
										'For classic-checkout stores under active attack only. This stops Store API checkout and must never be used as normal protection.',
										'coderembassy-order-guard'
									) }
							</p>
						</div>
						<label className="ceog-acknowledgement">
							<input
								type="checkbox"
								checked={ emergencyAcknowledged }
								onChange={ ( event ) =>
									setEmergencyAcknowledged( event.target.checked )
								}
								disabled={ meta.checkoutBlockDetected }
							/>
							<span>
								{ __( 'I understand the risks', 'coderembassy-order-guard' ) }
							</span>
						</label>
					</div>
				</section>
			</div>

			<div className="ceog-save-bar">
				{ saved && (
					<span className="ceog-saved" role="status">
						<CircleCheck size={ 15 } aria-hidden="true" />
						{ __( 'Protection settings saved', 'coderembassy-order-guard' ) }
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
						: __( 'Save protection settings', 'coderembassy-order-guard' ) }
				</button>
			</div>
		</div>
	);
}
