/**
 * Server-paginated protection event workspace.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Activity,
	ChevronLeft,
	ChevronRight,
	Download,
	ExternalLink,
	Filter,
	Fingerprint,
	RefreshCw,
	SearchX,
	Trash2,
	X,
} from 'lucide-react';
import { deleteLog, exportLogCsv, fetchLog, friendlyError } from '../api';
import ErrorState from '../components/ErrorState';

const EMPTY_FILTERS = {
	type: '',
	after: '',
	before: '',
	ip_hash: '',
};

const EVENT_TYPES = [
	'breaker_ip_trip',
	'breaker_email_trip',
	'breaker_global_trip',
	'blocked_add_item',
	'blocked_checkout',
	'blocked_batch_op',
	'flagged_order',
	'honeypot_hit',
	'blocklist_hit',
	'monitor_would_block',
	'auto_block_added',
	'auto_block_expired',
];

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
		case 'auto_block_added':
			return __( 'Temporary block added', 'coderembassy-order-vanguard' );
		case 'auto_block_expired':
			return __( 'Temporary block expired', 'coderembassy-order-vanguard' );
		default:
			return type || __( 'Unknown event', 'coderembassy-order-vanguard' );
	}
};

export default function ActivityLog( { isPro = false } ) {
	const [ draft, setDraft ] = useState( EMPTY_FILTERS );
	const [ filters, setFilters ] = useState( EMPTY_FILTERS );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( { rows: [], total: 0, pages: 0 } );
	const [ selectedIds, setSelectedIds ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ deleting, setDeleting ] = useState( false );
	const [ exporting, setExporting ] = useState( false );
	const [ exportNotice, setExportNotice ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	useEffect( () => {
		let active = true;

		const load = async () => {
			setLoading( true );
			setError( '' );

			try {
				const response = await fetchLog( {
					...filters,
					page,
					per_page: 25,
				} );

				if ( active ) {
					setResult( {
						rows: Array.isArray( response?.rows ) ? response.rows : [],
						total: Number( response?.total || 0 ),
						pages: Number( response?.pages || 0 ),
					} );
					setSelectedIds( [] );
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
	}, [ filters, page, reloadKey ] );

	const updateDraft = ( key, value ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const applyFilters = ( event ) => {
		event.preventDefault();
		setExportNotice( '' );
		setPage( 1 );
		setFilters( { ...draft } );
	};

	const clearFilters = () => {
		setExportNotice( '' );
		setDraft( EMPTY_FILTERS );
		setFilters( EMPTY_FILTERS );
		setPage( 1 );
	};

	const filterAttacker = ( ipHash ) => {
		setExportNotice( '' );
		const next = { ...EMPTY_FILTERS, ip_hash: ipHash };
		setDraft( next );
		setFilters( next );
		setPage( 1 );
	};

	const toggleRow = ( id ) => {
		setSelectedIds( ( current ) =>
			current.includes( id )
				? current.filter( ( selectedId ) => selectedId !== id )
				: [ ...current, id ]
		);
	};

	const allSelected =
		result.rows.length > 0 &&
		result.rows.every( ( row ) => selectedIds.includes( row.id ) );

	const togglePage = () => {
		setSelectedIds( allSelected ? [] : result.rows.map( ( row ) => row.id ) );
	};

	const removeSelected = async () => {
		if (
			selectedIds.length < 1 ||
			! window.confirm(
				sprintf(
					/* translators: %d: number of selected log rows. */
					__( 'Permanently delete %d selected event(s)?', 'coderembassy-order-vanguard' ),
					selectedIds.length
				)
			)
		) {
			return;
		}

		setDeleting( true );
		setError( '' );

		try {
			await deleteLog( selectedIds );
			const emptiedPage = selectedIds.length === result.rows.length && page > 1;
			setSelectedIds( [] );
			if ( emptiedPage ) {
				setPage( page - 1 );
			} else {
				setReloadKey( ( current ) => current + 1 );
			}
		} catch ( requestError ) {
			setError( friendlyError( requestError ) );
		} finally {
			setDeleting( false );
		}
	};

	const exportCurrentFilters = async () => {
		setExporting( true );
		setError( '' );
		setExportNotice( '' );

		try {
			const response = await exportLogCsv( filters );
			if ( typeof response?.content !== 'string' ) {
				throw new Error( __( 'Order Vanguard returned an invalid CSV export.', 'coderembassy-order-vanguard' ) );
			}

			const blob = new window.Blob( [ response.content ], {
				type: response.mimeType || 'text/csv;charset=utf-8',
			} );
			const downloadUrl = window.URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = downloadUrl;
			link.download = response.filename || 'order-guard-activity.csv';
			document.body.appendChild( link );
			link.click();
			link.remove();
			window.setTimeout( () => window.URL.revokeObjectURL( downloadUrl ), 0 );

			setExportNotice(
				response.truncated
					? sprintf(
						/* translators: 1: exported rows, 2: total matching rows. */
						__( 'Exported the first %1$d of %2$d matching events.', 'coderembassy-order-vanguard' ),
						Number( response.rows || 0 ),
						Number( response.total || 0 )
					)
					: sprintf(
						/* translators: %d: exported rows. */
						__( 'Exported %d matching events.', 'coderembassy-order-vanguard' ),
						Number( response.rows || 0 )
					)
			);
		} catch ( requestError ) {
			setError( friendlyError( requestError ) );
		} finally {
			setExporting( false );
		}
	};

	return (
		<div className="ceog-page ceog-log-page">
			<header className="ceog-page__header">
				<div>
					<p className="ceog-section-kicker">
						{ __( 'Protection history', 'coderembassy-order-vanguard' ) }
					</p>
					<h2>{ __( 'Activity Log', 'coderembassy-order-vanguard' ) }</h2>
					<p>
						{ __(
							'Review monitor decisions and enforced actions without loading the full log into your browser.',
							'coderembassy-order-vanguard'
						) }
					</p>
				</div>
				<span className="ceog-status ceog-status--success">
					<Activity size={ 15 } aria-hidden="true" />
					{ sprintf(
						/* translators: %d: number of matching protection events. */
						__( '%d events', 'coderembassy-order-vanguard' ),
						result.total
					) }
				</span>
			</header>

			<form className="ceog-log-filters" onSubmit={ applyFilters }>
				<label className="ceog-field" htmlFor="ceog-log-type">
					<span>{ __( 'Event type', 'coderembassy-order-vanguard' ) }</span>
					<select
						id="ceog-log-type"
						value={ draft.type }
						onChange={ ( event ) => updateDraft( 'type', event.target.value ) }
					>
						<option value="">{ __( 'All events', 'coderembassy-order-vanguard' ) }</option>
						{ EVENT_TYPES.map( ( type ) => (
							<option key={ type } value={ type }>
								{ eventLabel( type ) }
							</option>
						) ) }
					</select>
				</label>

				<label className="ceog-field" htmlFor="ceog-log-after">
					<span>{ __( 'From date', 'coderembassy-order-vanguard' ) }</span>
					<input
						id="ceog-log-after"
						type="date"
						value={ draft.after }
						max={ draft.before || undefined }
						onChange={ ( event ) => updateDraft( 'after', event.target.value ) }
					/>
				</label>

				<label className="ceog-field" htmlFor="ceog-log-before">
					<span>{ __( 'To date', 'coderembassy-order-vanguard' ) }</span>
					<input
						id="ceog-log-before"
						type="date"
						value={ draft.before }
						min={ draft.after || undefined }
						onChange={ ( event ) => updateDraft( 'before', event.target.value ) }
					/>
				</label>

				<div className="ceog-log-filter-actions">
					<button type="submit" className="ceog-button ceog-button--primary">
						<Filter size={ 16 } aria-hidden="true" />
						{ __( 'Apply filters', 'coderembassy-order-vanguard' ) }
					</button>
					<button type="button" className="ceog-button" onClick={ clearFilters }>
						<SearchX size={ 16 } aria-hidden="true" />
						{ __( 'Clear', 'coderembassy-order-vanguard' ) }
					</button>
				</div>
			</form>

			{ filters.ip_hash && (
				<div className="ceog-correlation-filter" role="status">
					<Fingerprint size={ 18 } aria-hidden="true" />
					<span>{ __( 'Showing events from the same attacker', 'coderembassy-order-vanguard' ) }</span>
					<code>{ filters.ip_hash.slice( 0, 12 ) + '...' }</code>
					<button
						type="button"
						className="ceog-icon-button"
						onClick={ clearFilters }
						aria-label={ __( 'Clear attacker filter', 'coderembassy-order-vanguard' ) }
						title={ __( 'Clear attacker filter', 'coderembassy-order-vanguard' ) }
					>
						<X size={ 16 } aria-hidden="true" />
					</button>
				</div>
			) }

			{ exportNotice && <div className="ceog-log-export-notice" role="status"><Download size={ 17 } aria-hidden="true" /><span>{ exportNotice }</span></div> }

			{ error && (
				<ErrorState
					message={ error }
					onRetry={ () => setReloadKey( ( current ) => current + 1 ) }
				/>
			) }

			<section className="ceog-log-panel" aria-busy={ loading }>
				<div className="ceog-log-actions">
					<span>
						{ selectedIds.length > 0
							? sprintf(
								/* translators: %d: number of selected log rows. */
								__( '%d selected', 'coderembassy-order-vanguard' ),
								selectedIds.length
							)
							: __( 'Select rows to delete', 'coderembassy-order-vanguard' ) }
					</span>
					<div>
						{ isPro && (
							<button
								type="button"
								className="ceog-button"
								onClick={ exportCurrentFilters }
								disabled={ loading || exporting }
							>
								<Download size={ 16 } aria-hidden="true" />
								{ exporting
									? __( 'Preparing CSV...', 'coderembassy-order-vanguard' )
									: __( 'Export filtered CSV', 'coderembassy-order-vanguard' ) }
							</button>
						) }
						<button
							type="button"
							className="ceog-icon-button"
							onClick={ () => setReloadKey( ( current ) => current + 1 ) }
							disabled={ loading }
							aria-label={ __( 'Refresh events', 'coderembassy-order-vanguard' ) }
							title={ __( 'Refresh events', 'coderembassy-order-vanguard' ) }
						>
							<RefreshCw size={ 16 } aria-hidden="true" />
						</button>
						<button
							type="button"
							className="ceog-button ceog-button--danger"
							onClick={ removeSelected }
							disabled={ selectedIds.length < 1 || deleting }
						>
							<Trash2 size={ 16 } aria-hidden="true" />
							{ deleting
								? __( 'Deleting...', 'coderembassy-order-vanguard' )
								: __( 'Delete selected', 'coderembassy-order-vanguard' ) }
						</button>
					</div>
				</div>

				{ loading && result.rows.length < 1 ? (
					<div className="ceog-log-loading">
						<RefreshCw size={ 22 } aria-hidden="true" />
						<span>{ __( 'Loading protection events...', 'coderembassy-order-vanguard' ) }</span>
					</div>
				) : result.rows.length < 1 ? (
					<div className="ceog-log-empty">
						<Activity size={ 26 } aria-hidden="true" />
						<h3>{ __( 'No matching protection events', 'coderembassy-order-vanguard' ) }</h3>
						<p>
							{ __(
								'Events recorded by future protection rules will appear here. Adjust the filters if you expected existing rows.',
								'coderembassy-order-vanguard'
							) }
						</p>
					</div>
				) : (
					<div className="ceog-log-table-wrap">
						<table className="ceog-log-table">
							<thead>
								<tr>
									<th className="ceog-log-table__select">
										<input
											type="checkbox"
											checked={ allSelected }
											onChange={ togglePage }
											aria-label={ __( 'Select this page', 'coderembassy-order-vanguard' ) }
										/>
									</th>
									<th>{ __( 'Time', 'coderembassy-order-vanguard' ) }</th>
									<th>{ __( 'Type', 'coderembassy-order-vanguard' ) }</th>
									<th>{ __( 'Mode', 'coderembassy-order-vanguard' ) }</th>
									<th>{ __( 'IP', 'coderembassy-order-vanguard' ) }</th>
									<th>{ __( 'Route', 'coderembassy-order-vanguard' ) }</th>
									<th>{ __( 'Reason', 'coderembassy-order-vanguard' ) }</th>
									<th>{ __( 'Order', 'coderembassy-order-vanguard' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ result.rows.map( ( row ) => (
									<tr key={ row.id }>
										<td className="ceog-log-table__select">
											<input
												type="checkbox"
												checked={ selectedIds.includes( row.id ) }
												onChange={ () => toggleRow( row.id ) }
												aria-label={ sprintf(
													/* translators: %d: log row ID. */
													__( 'Select event %d', 'coderembassy-order-vanguard' ),
													row.id
												) }
											/>
										</td>
										<td className="ceog-log-table__time">{ row.event_time }</td>
										<td>
											<span className="ceog-event-type">
												{ eventLabel( row.event_type ) }
											</span>
										</td>
										<td>
											<span className={ 'ceog-mode-badge ceog-mode-badge--' + row.mode }>
												{ row.mode === 'enforce'
													? __( 'Enforce', 'coderembassy-order-vanguard' )
													: __( 'Monitor', 'coderembassy-order-vanguard' ) }
											</span>
										</td>
										<td>
											<div className="ceog-log-ip">
												<code>{ row.ip_display || '-' }</code>
												{ row.ip_hash && (
													<button
														type="button"
														className="ceog-icon-button ceog-icon-button--small"
														onClick={ () => filterAttacker( row.ip_hash ) }
														aria-label={ __( 'Show the same attacker', 'coderembassy-order-vanguard' ) }
														title={ __( 'Show the same attacker', 'coderembassy-order-vanguard' ) }
													>
														<Fingerprint size={ 14 } aria-hidden="true" />
													</button>
												) }
											</div>
										</td>
										<td className="ceog-log-table__route">{ row.route || '-' }</td>
										<td className="ceog-log-table__reason">{ row.reason || '-' }</td>
										<td>
											{ row.order_id > 0 && row.order_url ? (
												<a href={ row.order_url } target="_blank" rel="noreferrer">
													{ '#' + row.order_id }
													<ExternalLink size={ 13 } aria-hidden="true" />
												</a>
											) : row.order_id > 0 ? (
												'#' + row.order_id
											) : (
												'-'
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }

				<div className="ceog-log-pagination">
					<span>
						{ result.pages > 0
							? sprintf(
								/* translators: 1: current page, 2: total pages. */
								__( 'Page %1$d of %2$d', 'coderembassy-order-vanguard' ),
								page,
								result.pages
							)
							: __( 'No pages', 'coderembassy-order-vanguard' ) }
					</span>
					<div>
						<button
							type="button"
							className="ceog-button"
							onClick={ () => setPage( Math.max( 1, page - 1 ) ) }
							disabled={ page <= 1 || loading }
						>
							<ChevronLeft size={ 16 } aria-hidden="true" />
							{ __( 'Previous', 'coderembassy-order-vanguard' ) }
						</button>
						<button
							type="button"
							className="ceog-button"
							onClick={ () => setPage( page + 1 ) }
							disabled={ page >= result.pages || loading }
						>
							{ __( 'Next', 'coderembassy-order-vanguard' ) }
							<ChevronRight size={ 16 } aria-hidden="true" />
						</button>
					</div>
				</div>
			</section>
		</div>
	);
}
