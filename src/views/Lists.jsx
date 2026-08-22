/**
 * Manual blocklist and whitelist editors.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Ban,
	CircleCheck,
	CreditCard,
	Globe2,
	Mail,
	Save,
	ShieldCheck,
	UserRoundCheck,
} from 'lucide-react';
import { fetchLists, friendlyError, saveLists } from '../api';
import ErrorState from '../components/ErrorState';
import SearchMultiSelect from '../components/SearchMultiSelect';

const emptyDraft = {
	blockEmails: '',
	blockDomains: '',
	blockIps: '',
	allowIps: '',
	allowRoles: [],
	allowPayments: [],
};

const joinLines = ( values ) => ( Array.isArray( values ) ? values.join( '\n' ) : '' );

const makeDraft = ( payload = {} ) => ( {
	blockEmails: joinLines( payload.blocklist?.emails ),
	blockDomains: joinLines( payload.blocklist?.domains ),
	blockIps: joinLines( payload.blocklist?.ips ),
	allowIps: joinLines( payload.whitelist?.ips ),
	allowRoles: Array.isArray( payload.whitelist?.roles ) ? payload.whitelist.roles : [],
	allowPayments: Array.isArray( payload.whitelist?.payment_methods )
		? payload.whitelist.payment_methods
		: [],
} );

const splitLines = ( value ) =>
	String( value )
		.split( /\r?\n/ )
		.map( ( item ) => item.trim() )
		.filter( Boolean );

function ListField( { id, label, description, value, onChange, placeholder } ) {
	return (
		<label className="ceog-list-field" htmlFor={ id }>
			<span>{ label }</span>
			<small>{ description }</small>
			<textarea
				id={ id }
				rows={ 5 }
				value={ value }
				placeholder={ placeholder }
				autoCapitalize="none"
				autoCorrect="off"
				spellCheck="false"
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		</label>
	);
}

export default function Lists() {
	const [ payload, setPayload ] = useState( null );
	const [ draft, setDraft ] = useState( emptyDraft );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	useEffect( () => {
		let active = true;

		const load = async () => {
			setLoading( true );
			setError( '' );

			try {
				const response = await fetchLists();
				if ( active ) {
					setPayload( response );
					setDraft( makeDraft( response ) );
					setSaved( false );
				}
			} catch ( requestError ) {
				if ( active ) {
					setError( friendlyError( requestError ) );
				}
			} finally {
				if ( active ) {
					setLoading( false );
				}
			}
		};

		load();

		return () => {
			active = false;
		};
	}, [ reloadKey ] );

	const update = ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
		setSaved( false );
	};

	const save = async () => {
		setSaving( true );
		setError( '' );

		try {
			const response = await saveLists( {
				blocklist_emails: splitLines( draft.blockEmails ),
				blocklist_email_domains: splitLines( draft.blockDomains ),
				blocklist_ips: splitLines( draft.blockIps ),
				whitelist_ips: splitLines( draft.allowIps ),
				whitelist_roles: draft.allowRoles,
				whitelist_payment_methods: draft.allowPayments,
			} );
			setPayload( response );
			setDraft( makeDraft( response ) );
			setSaved( true );
		} catch ( requestError ) {
			setError( friendlyError( requestError ) );
			setSaved( false );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="ceog-page ceog-lists-page">
			<header className="ceog-page__header">
				<div>
					<p className="ceog-section-kicker">
						{ __( 'Access controls', 'coderembassy-order-guard' ) }
					</p>
					<h2>{ __( 'Lists', 'coderembassy-order-guard' ) }</h2>
					<p>
						{ __(
							'Manually block known sources and preserve trusted checkout paths.',
							'coderembassy-order-guard'
						) }
					</p>
				</div>
				<div className="ceog-list-counts">
					<span className="ceog-status ceog-status--warning">
						<Ban size={ 15 } aria-hidden="true" />
						{ sprintf(
							/* translators: %d: number of blocklist entries. */
							__( '%d blocked', 'coderembassy-order-guard' ),
							Number( payload?.counts?.blocked || 0 )
						) }
					</span>
					<span className="ceog-status ceog-status--success">
						<ShieldCheck size={ 15 } aria-hidden="true" />
						{ sprintf(
							/* translators: %d: number of whitelist entries. */
							__( '%d allowed', 'coderembassy-order-guard' ),
							Number( payload?.counts?.allowed || 0 )
						) }
					</span>
				</div>
			</header>

			<div className="ceog-list-precedence" role="status">
				<ShieldCheck size={ 19 } aria-hidden="true" />
				<div>
					<strong>{ __( 'Whitelist always wins', 'coderembassy-order-guard' ) }</strong>
					<span>
						{ __(
							'A matching role, IP, or payment method bypasses blocklists and future protection rules.',
							'coderembassy-order-guard'
						) }
					</span>
				</div>
			</div>

			{ error && (
				<ErrorState
					message={ error }
					onRetry={ () => setReloadKey( ( current ) => current + 1 ) }
				/>
			) }

			{ loading ? (
				<div className="ceog-list-grid" aria-busy="true">
					<div className="ceog-skeleton ceog-skeleton--list" />
					<div className="ceog-skeleton ceog-skeleton--list" />
				</div>
			) : (
				<div className="ceog-list-grid">
					<section className="ceog-list-panel ceog-list-panel--block">
						<div className="ceog-list-panel__heading">
							<Ban size={ 21 } aria-hidden="true" />
							<div>
								<h3>{ __( 'Blocklist', 'coderembassy-order-guard' ) }</h3>
								<p>
									{ __(
										'Matches are logged in Monitor mode and stopped in Enforce mode.',
										'coderembassy-order-guard'
									) }
								</p>
							</div>
						</div>

						<ListField
							id="ceog-block-emails"
							label={ __( 'Billing emails', 'coderembassy-order-guard' ) }
							description={ __( 'Exact email addresses, one per line.', 'coderembassy-order-guard' ) }
							value={ draft.blockEmails }
							onChange={ ( value ) => update( 'blockEmails', value ) }
							placeholder="bot@example.com"
						/>
						<ListField
							id="ceog-block-domains"
							label={ __( 'Email domains', 'coderembassy-order-guard' ) }
							description={ __( 'Domain names without @, one per line.', 'coderembassy-order-guard' ) }
							value={ draft.blockDomains }
							onChange={ ( value ) => update( 'blockDomains', value ) }
							placeholder="example.com"
						/>
						<ListField
							id="ceog-block-ips"
							label={ __( 'IP addresses', 'coderembassy-order-guard' ) }
							description={ __(
								'IPv4 matches exactly; IPv6 matches its entire /64 network.',
								'coderembassy-order-guard'
							) }
							value={ draft.blockIps }
							onChange={ ( value ) => update( 'blockIps', value ) }
							placeholder="198.51.100.20"
						/>
					</section>

					<section className="ceog-list-panel ceog-list-panel--allow">
						<div className="ceog-list-panel__heading">
							<ShieldCheck size={ 21 } aria-hidden="true" />
							<div>
								<h3>{ __( 'Whitelist', 'coderembassy-order-guard' ) }</h3>
								<p>
									{ __(
										'Use narrow exceptions for trusted staff, infrastructure, and checkout methods.',
										'coderembassy-order-guard'
									) }
								</p>
							</div>
						</div>

						<div className="ceog-list-field-icon">
							<Globe2 size={ 17 } aria-hidden="true" />
							<ListField
								id="ceog-allow-ips"
								label={ __( 'Trusted IP addresses', 'coderembassy-order-guard' ) }
								description={ __( 'IPv6 exceptions cover the entered /64 network.', 'coderembassy-order-guard' ) }
								value={ draft.allowIps }
								onChange={ ( value ) => update( 'allowIps', value ) }
								placeholder="203.0.113.10"
							/>
						</div>
						<SearchMultiSelect
							id="ceog-allow-roles"
							label={ __( 'WordPress roles', 'coderembassy-order-guard' ) }
							description={ __( 'Search and select the roles that should always bypass protection.', 'coderembassy-order-guard' ) }
							icon={ UserRoundCheck }
							options={ payload?.options?.roles }
							value={ draft.allowRoles }
							onChange={ ( value ) => update( 'allowRoles', value ) }
							searchPlaceholder={ __( 'Search WordPress roles...', 'coderembassy-order-guard' ) }
							emptyText={ __( 'No matching WordPress roles.', 'coderembassy-order-guard' ) }
						/>
						<SearchMultiSelect
							id="ceog-allow-payments"
							label={ __( 'Payment methods', 'coderembassy-order-guard' ) }
							description={ __(
								'Search installed gateways or add a custom gateway key manually.',
								'coderembassy-order-guard'
							) }
							icon={ CreditCard }
							options={ payload?.options?.payment_methods }
							value={ draft.allowPayments }
							onChange={ ( value ) => update( 'allowPayments', value ) }
							searchPlaceholder={ __( 'Search payment methods...', 'coderembassy-order-guard' ) }
							emptyText={ __( 'No matching payment methods.', 'coderembassy-order-guard' ) }
							allowManual
							manualPlaceholder={ __( 'Custom gateway key', 'coderembassy-order-guard' ) }
						/>
						<div className="ceog-list-compatibility">
							<Mail size={ 17 } aria-hidden="true" />
							<span>
								{ __(
									'Confirm gateway keys in WooCommerce payment settings before adding an exception.',
									'coderembassy-order-guard'
								) }
							</span>
						</div>
					</section>
				</div>
			) }

			<div className="ceog-save-bar">
				{ saved && (
					<span className="ceog-saved" role="status">
						<CircleCheck size={ 15 } aria-hidden="true" />
						{ __( 'Lists saved', 'coderembassy-order-guard' ) }
					</span>
				) }
				<button
					type="button"
					className="ceog-button ceog-button--primary"
					onClick={ save }
					disabled={ loading || saving }
				>
					<Save size={ 16 } aria-hidden="true" />
					{ saving
						? __( 'Saving...', 'coderembassy-order-guard' )
						: __( 'Save lists', 'coderembassy-order-guard' ) }
				</button>
			</div>
		</div>
	);
}
