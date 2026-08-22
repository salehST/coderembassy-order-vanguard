/**
 * Order Guard React shell and shared data lifecycle.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchSettings, friendlyError, saveMode, saveSettings } from './api';
import ErrorState from './components/ErrorState';
import Sidebar from './components/Sidebar';
import Topbar from './components/Topbar';
import { ExpiredLicenseModal, LicenseBanner } from './components/LicenseNotice';
import { navigateTo, readRoute } from './navigation';
import ActivityLog from './views/ActivityLog';
import CircuitBreakers from './views/CircuitBreakers';
import Dashboard from './views/Dashboard';
import Lists from './views/Lists';
import Placeholder from './views/Placeholder';
import PrivacyLogs from './views/PrivacyLogs';
import ProWorkspace from './views/ProWorkspace';
import SettingsView from './views/Settings';
import StoreApiGuard from './views/StoreApiGuard';

const THEME_KEY = 'ceog_admin_theme';

const readTheme = () => {
	try {
		return window.localStorage.getItem( THEME_KEY ) || 'light';
	} catch ( error ) {
		void error;
		return 'light';
	}
};

export default function App( { boot } ) {
	const [ route, setRoute ] = useState( readRoute );
	const [ theme, setTheme ] = useState( readTheme );
	const [ payload, setPayload ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ modeBusy, setModeBusy ] = useState( false );
	const [ settingsBusy, setSettingsBusy ] = useState( false );
	const [ mobileMenuOpen, setMobileMenuOpen ] = useState( false );
	const [ licenseStatus, setLicenseStatus ] = useState( boot.licenseStatus || null );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );

		try {
			setPayload( await fetchSettings() );
		} catch ( requestError ) {
			setError( friendlyError( requestError ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		const onHashChange = () => setRoute( readRoute() );
		window.addEventListener( 'hashchange', onHashChange );
		onHashChange();

		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	useEffect( () => {
		const onLicenseUpdated = ( event ) => {
			if ( event?.detail?.license ) {
				setLicenseStatus( event.detail.license );
			}
		};
		window.addEventListener( 'ceog-license-updated', onLicenseUpdated );

		return () => window.removeEventListener( 'ceog-license-updated', onLicenseUpdated );
	}, [] );

	useEffect( () => {
		const proWorkspaceRoutes = [ 'history-reporting', 'auto-blocklist', 'attack-cleanup', 'turnstile' ];
		if ( licenseStatus?.gate?.locked && proWorkspaceRoutes.includes( route ) ) {
			navigateTo( 'pro-license' );
		}
	}, [ licenseStatus, route ] );

	const closeMobileMenu = useCallback( ( restoreFocus = false ) => {
		setMobileMenuOpen( false );

		if ( restoreFocus ) {
			window.setTimeout( () => {
				document.getElementById( 'ceog-mobile-menu-trigger' )?.focus();
			}, 50 );
		}
	}, [] );

	useEffect( () => {
		setMobileMenuOpen( false );
	}, [ route ] );

	useEffect( () => {
		if ( ! mobileMenuOpen ) {
			return undefined;
		}

		const widerScreen = window.matchMedia( '(min-width: 783px)' );
		const onKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				closeMobileMenu( true );
			}
		};
		const onViewportChange = ( event ) => {
			if ( event.matches ) {
				closeMobileMenu();
			}
		};

		document.body.classList.add( 'ceog-mobile-menu-open' );
		const previousBodyOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		const main = document.getElementById( 'ceog-main' );
		main?.setAttribute( 'inert', '' );
		main?.setAttribute( 'aria-hidden', 'true' );
		const focusTimer = window.setTimeout( () => {
			document.querySelector( '.ceog-sidebar__close' )?.focus();
		}, 80 );
		document.addEventListener( 'keydown', onKeyDown );
		widerScreen.addEventListener( 'change', onViewportChange );

		return () => {
			window.clearTimeout( focusTimer );
			document.body.classList.remove( 'ceog-mobile-menu-open' );
			document.body.style.overflow = previousBodyOverflow;
			main?.removeAttribute( 'inert' );
			main?.removeAttribute( 'aria-hidden' );
			document.removeEventListener( 'keydown', onKeyDown );
			widerScreen.removeEventListener( 'change', onViewportChange );
		};
	}, [ closeMobileMenu, mobileMenuOpen ] );

	useEffect( () => {
		const root = document.getElementById( 'ceog-app' );
		root?.setAttribute( 'data-theme', theme );

		try {
			window.localStorage.setItem( THEME_KEY, theme );
		} catch ( storageError ) {
			void storageError;
		}
	}, [ theme ] );

	const changeMode = async ( nextMode ) => {
		if (
			nextMode === 'enforce' &&
			! window.confirm(
				__(
					'We recommend reviewing the Activity Log first. Switch to Enforce mode?',
					'coderembassy-order-guard'
				)
			)
		) {
			return;
		}

		setModeBusy( true );
		setError( '' );

		try {
			const response = await saveMode( nextMode );
			setPayload( ( current ) => {
				const base = current || { settings: {}, meta: {} };

				return {
					...base,
					settings: { ...base.settings, mode: response.mode },
					meta: {
						...base.meta,
						enforcing: response.enforcing,
						safeMode: response.safeMode,
					},
				};
			} );
		} catch ( requestError ) {
			setError( friendlyError( requestError ) );
		} finally {
			setModeBusy( false );
		}
	};

	const updateSettings = async ( patch ) => {
		setSettingsBusy( true );
		setError( '' );

		try {
			setPayload( await saveSettings( patch ) );
			return true;
		} catch ( requestError ) {
			setError( friendlyError( requestError ) );
			return false;
		} finally {
			setSettingsBusy( false );
		}
	};

	let view = <Placeholder route={ route } boot={ boot } />;

	if ( route === 'dashboard' ) {
		view = (
			<Dashboard
				payload={ payload }
				loading={ loading }
				modeBusy={ modeBusy }
				onModeChange={ changeMode }
				isPro={ Boolean( boot.isPro ) }
			/>
		);
	} else if ( route === 'activity-log' ) {
		view = <ActivityLog isPro={ Boolean( boot.isPro ) } />;
	} else if ( route === 'history-reporting' && boot.isPro ) {
		view = <ProWorkspace view="history-reporting" />;
	} else if ( route === 'circuit-breakers' ) {
		view = (
			<CircuitBreakers
				payload={ payload }
				loading={ loading }
				settingsBusy={ settingsBusy }
				onSave={ updateSettings }
			/>
		);
	} else if ( route === 'lists' ) {
		view = <Lists />;
	} else if ( route === 'store-api' ) {
		view = (
			<StoreApiGuard
				payload={ payload }
				loading={ loading }
				settingsBusy={ settingsBusy }
				onSave={ updateSettings }
				isPro={ Boolean( boot.isPro ) }
			/>
		);
	} else if ( route === 'turnstile' && boot.isPro ) {
		view = <ProWorkspace view="turnstile" />;
	} else if ( route === 'alerts' && boot.isPro ) {
		view = <ProWorkspace view="alerts" />;
	} else if ( route === 'settings' ) {
		view = (
			<SettingsView
				payload={ payload }
				modeBusy={ modeBusy }
				settingsBusy={ settingsBusy }
				onModeChange={ changeMode }
				onSave={ updateSettings }
			/>
		);
	} else if ( route === 'privacy-logs' ) {
		view = (
			<PrivacyLogs
				payload={ payload }
				settingsBusy={ settingsBusy }
				onSave={ updateSettings }
			/>
		);
	} else if ( route === 'attack-cleanup' && boot.isPro ) {
		view = <ProWorkspace view="cleanup" />;
	} else if ( route === 'pro-license' && boot.isPro ) {
		view = <ProWorkspace view="license" />;
	} else if ( route === 'auto-blocklist' && boot.isPro ) {
		view = <ProWorkspace view="auto-blocklist" />;
	}

	return (
		<div className="ceog-shell">
			<ExpiredLicenseModal boot={ { ...boot, licenseStatus } } />
			<Sidebar
				route={ route }
				theme={ theme }
				boot={ boot }
				mobileOpen={ mobileMenuOpen }
				onDismiss={ () => closeMobileMenu( true ) }
				onNavigate={ () => closeMobileMenu() }
			/>
			<main id="ceog-main" className="ceog-main">
				<Topbar
					route={ route }
					theme={ theme }
					boot={ boot }
					onToggleTheme={ () =>
						setTheme( theme === 'dark' ? 'light' : 'dark' )
					}
					user={ boot.user }
					mobileMenuOpen={ mobileMenuOpen }
					onOpenMenu={ () => setMobileMenuOpen( true ) }
				/>
				<LicenseBanner boot={ { ...boot, licenseStatus } } />
				<div className="ceog-content">
					{ error && <ErrorState message={ error } onRetry={ load } /> }
					{ view }
				</div>
			</main>
		</div>
	);
}
