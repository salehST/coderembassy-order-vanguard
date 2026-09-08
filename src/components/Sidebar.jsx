/**
 * Branded product navigation.
 */
import { __ } from '@wordpress/i18n';
import { X } from 'lucide-react';
import { NAV_ITEMS, navigateTo } from '../navigation';

export default function Sidebar( {
	route,
	theme,
	boot,
	mobileOpen,
	onDismiss,
	onNavigate,
} ) {
	const logo = theme === 'dark' ? boot.logoDark : boot.logoLight;

	return (
		<>
			{ mobileOpen && (
				<button
					type="button"
					className="ceog-sidebar-overlay"
					onClick={ onDismiss }
					aria-label={ __( 'Close navigation', 'coderembassy-order-vanguard' ) }
					tabIndex="-1"
				/>
			) }
		<aside
			id="ceog-mobile-navigation"
			className={
				'ceog-sidebar' + ( mobileOpen ? ' is-mobile-open' : '' )
			}
			aria-label={ __( 'Order Vanguard navigation', 'coderembassy-order-vanguard' ) }
		>
			<div className="ceog-sidebar__header">
				{ logo ? (
					<img
						className="ceog-sidebar__logo"
						src={ logo }
						alt={ __( 'CoderEmbassy', 'coderembassy-order-vanguard' ) }
					/>
				) : (
					<strong className="ceog-sidebar__brand">
						{ __( 'CoderEmbassy', 'coderembassy-order-vanguard' ) }
					</strong>
				) }
				{ mobileOpen && (
					<button
						type="button"
						className="ceog-icon-button ceog-sidebar__close"
						onClick={ onDismiss }
						aria-label={ __( 'Close navigation', 'coderembassy-order-vanguard' ) }
						title={ __( 'Close navigation', 'coderembassy-order-vanguard' ) }
					>
						<X size={ 20 } aria-hidden="true" />
					</button>
				) }
			</div>

			<nav
				className="ceog-sidebar__nav"
				aria-label={ __( 'Order Vanguard', 'coderembassy-order-vanguard' ) }
			>
				{ NAV_ITEMS.filter( ( item ) => ! item.proOnly || boot.isPro ).map( ( item ) => {
					const Icon = item.icon;
					const active = route === item.id;

					return (
						<button
							key={ item.id }
							type="button"
							className={ 'ceog-nav-item' + ( active ? ' is-active' : '' ) }
							onClick={ () => {
								navigateTo( item.id );
								onNavigate();
							} }
							aria-current={ active ? 'page' : undefined }
						>
							<Icon size={ 18 } aria-hidden="true" />
							<span>{ item.label() }</span>
						</button>
					);
				} ) }
			</nav>

			<div className="ceog-sidebar__footer">
				<span className="ceog-badge ceog-badge--free">
					{ boot.isPro ? __( 'Pro active', 'coderembassy-order-vanguard' ) : __( 'Free', 'coderembassy-order-vanguard' ) }
				</span>
				<span>
					{ __( 'Version', 'coderembassy-order-vanguard' ) }{ ' ' }
					{ boot.version || '1.0.16' }
				</span>
			</div>
		</aside>
		</>
	);
}
