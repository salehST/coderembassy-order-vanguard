import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { KeyRound, ShieldAlert, X } from 'lucide-react';
import { navigateTo } from '../navigation';

const DISMISS_KEY = 'ceog_license_banner_dismissed';

const readDismissed = () => {
	try {
		return window.sessionStorage.getItem( DISMISS_KEY ) === '1';
	} catch ( error ) {
		void error;
		return false;
	}
};

export function LicenseBanner( { boot } ) {
	const license = boot?.licenseStatus;
	const [ dismissed, setDismissed ] = useState( readDismissed );

	if ( ! boot?.isPro || ! license ) {
		return null;
	}

	const state = license.enforcementState || 'INACTIVE';
	const locked = Boolean( license.gate?.locked );
	const nagDays = Number( license.gate?.nagDaysRemaining || 0 );
	const shouldShow =
		! license.hasKey ||
		locked ||
		nagDays > 0 ||
		[ 'EXPIRED', 'GRACE_PERIOD', 'INVALID', 'UNREACHABLE' ].includes(
			state
		);

	if ( ! shouldShow || ( dismissed && ! locked ) ) {
		return null;
	}

	let tone = 'warning';
	let message = __(
		'Order Vanguard Pro requires a license key for commercial updates and continued Pro workspace access.',
		'coderembassy-order-vanguard'
	);
	let action = __( 'Activate license', 'coderembassy-order-vanguard' );

	if ( locked ) {
		tone = 'locked';
		message = __(
			'A license key is required to continue managing Order Vanguard Pro workspaces. Free protection remains available.',
			'coderembassy-order-vanguard'
		);
	} else if ( nagDays > 0 ) {
		message = sprintf(
			/* translators: %d: trial days remaining */
			__( '%d days remain to activate Order Vanguard Pro.', 'coderembassy-order-vanguard' ),
			nagDays
		);
	} else if ( state === 'GRACE_PERIOD' ) {
		message = __(
			'Your Order Vanguard Pro license is in its grace period. Renew soon to keep updates and Pro workspace access.',
			'coderembassy-order-vanguard'
		);
		action = __( 'Renew license', 'coderembassy-order-vanguard' );
	} else if ( state === 'EXPIRED' ) {
		tone = 'error';
		message = __(
			'Your Order Vanguard Pro license has expired. Renew it to restore updates and Pro workspace access.',
			'coderembassy-order-vanguard'
		);
		action = __( 'Renew license', 'coderembassy-order-vanguard' );
	} else if ( state === 'INVALID' ) {
		tone = 'error';
		message = __(
			'Your Order Vanguard Pro license could not be verified. Review the saved key.',
			'coderembassy-order-vanguard'
		);
		action = __( 'Check license', 'coderembassy-order-vanguard' );
	} else if ( state === 'UNREACHABLE' ) {
		message = __(
			'The license server is temporarily unreachable. Order Vanguard Pro is using its last known status.',
			'coderembassy-order-vanguard'
		);
		action = __( 'Review license', 'coderembassy-order-vanguard' );
	}

	const dismiss = () => {
		try {
			window.sessionStorage.setItem( DISMISS_KEY, '1' );
		} catch ( error ) {
			void error;
		}
		setDismissed( true );
	};

	return (
		<div className={ 'ceog-license-banner is-' + tone } role={ locked ? 'alert' : 'status' }>
			<ShieldAlert size={ 19 } aria-hidden="true" />
			<span>{ message }</span>
			<button type="button" className="ceog-license-banner__action" onClick={ () => navigateTo( 'pro-license' ) }>
				<KeyRound size={ 16 } aria-hidden="true" />
				{ action }
			</button>
			{ ! locked && (
				<button type="button" className="ceog-license-banner__dismiss" onClick={ dismiss } aria-label={ __( 'Dismiss license notice', 'coderembassy-order-vanguard' ) } title={ __( 'Dismiss license notice', 'coderembassy-order-vanguard' ) }>
					<X size={ 17 } aria-hidden="true" />
				</button>
			) }
		</div>
	);
}

export function ExpiredLicenseModal( { boot } ) {
	const modal = boot?.expiredModal;
	const initialSeconds = Math.max( 1, Number( modal?.autoCloseSeconds || 15 ) );
	const [ open, setOpen ] = useState( Boolean( boot?.isPro && modal?.show ) );
	const [ remaining, setRemaining ] = useState( initialSeconds );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}

		const onKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				setOpen( false );
			}
		};
		const timer = window.setInterval( () => {
			setRemaining( ( current ) => {
				if ( current <= 1 ) {
					window.clearInterval( timer );
					setOpen( false );
					return 0;
				}
				return current - 1;
			} );
		}, 1000 );

		document.addEventListener( 'keydown', onKeyDown );
		window.setTimeout( () => document.getElementById( 'ceog-license-renew' )?.focus(), 50 );

		if ( boot.licenseApi?.modalShownUrl ) {
			window.fetch( boot.licenseApi.modalShownUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': boot.nonce || '' },
			} ).catch( () => {} );
		}

		return () => {
			window.clearInterval( timer );
			document.removeEventListener( 'keydown', onKeyDown );
		};
	}, [ open ] );

	if ( ! open ) {
		return null;
	}

	return (
		<div className="ceog-license-modal" onMouseDown={ ( event ) => event.target === event.currentTarget && setOpen( false ) }>
			<div className="ceog-license-modal__card" role="alertdialog" aria-modal="true" aria-labelledby="ceog-license-expired-title" aria-describedby="ceog-license-expired-body">
				<button type="button" className="ceog-license-modal__close" onClick={ () => setOpen( false ) } aria-label={ __( 'Close', 'coderembassy-order-vanguard' ) }>
					<X size={ 18 } aria-hidden="true" />
				</button>
				<div className="ceog-license-modal__icon"><ShieldAlert size={ 30 } aria-hidden="true" /></div>
				<h2 id="ceog-license-expired-title">{ __( 'Your Order Vanguard Pro license has expired', 'coderembassy-order-vanguard' ) }</h2>
				<p id="ceog-license-expired-body">{ __( 'The grace period is over. Renew now to restore private updates and Pro workspace access. Existing Free protection remains available.', 'coderembassy-order-vanguard' ) }</p>
				<a id="ceog-license-renew" className="ceog-button ceog-button--primary" href={ modal.renewUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Renew license', 'coderembassy-order-vanguard' ) }
				</a>
				<small aria-live="polite">
					{ sprintf(
						/* translators: %d: seconds until the modal closes */
						__( 'This reminder closes in %d seconds.', 'coderembassy-order-vanguard' ),
						remaining
					) }
				</small>
			</div>
		</div>
	);
}
