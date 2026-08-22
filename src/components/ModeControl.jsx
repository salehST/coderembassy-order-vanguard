/**
 * Accessible Monitor/Enforce segmented control.
 */
import { __ } from '@wordpress/i18n';
import { Eye, ShieldCheck } from 'lucide-react';

export default function ModeControl( {
	mode,
	onChange,
	busy = false,
	compact = false,
} ) {
	return (
		<div
			className={
				'ceog-mode-control' +
				( compact ? ' ceog-mode-control--compact' : '' )
			}
			role="group"
			aria-label={ __( 'Protection mode', 'coderembassy-order-guard' ) }
		>
			<button
				type="button"
				className={ mode === 'monitor' ? 'is-active' : '' }
				onClick={ () => onChange( 'monitor' ) }
				aria-pressed={ mode === 'monitor' }
				disabled={ busy }
			>
				<Eye size={ 16 } aria-hidden="true" />
				{ __( 'Monitor', 'coderembassy-order-guard' ) }
			</button>
			<button
				type="button"
				className={ mode === 'enforce' ? 'is-active' : '' }
				onClick={ () => onChange( 'enforce' ) }
				aria-pressed={ mode === 'enforce' }
				disabled={ busy }
			>
				<ShieldCheck size={ 16 } aria-hidden="true" />
				{ __( 'Enforce', 'coderembassy-order-guard' ) }
			</button>
		</div>
	);
}

