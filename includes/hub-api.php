<?php
/**
 * Hub API helpers for maca BackUp (shared by REST layer).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site key stored by maca Sec Hub reporter.
 *
 * @return string
 */
function maca_backup_pro_hub_get_site_key() {
	return trim( (string) get_option( 'maca_sec_hub_site_key', '' ) );
}

/**
 * Trim a backup DB row for Hub (no manifest/inventory/path).
 *
 * @param object|null $row Backup row.
 * @return array<string, mixed>|null
 */
function maca_backup_pro_hub_map_backup( $row ) {
	if ( ! is_object( $row ) ) {
		return null;
	}

	$started  = (string) ( $row->started_at ?? '' );
	$finished = (string) ( $row->finished_at ?? '' );
	$created  = (string) ( $row->created_at ?? '' );
	$when     = '' !== $finished && '0000-00-00 00:00:00' !== $finished ? $finished : ( '' !== $created ? $created : $started );

	$checksum = '';
	if ( class_exists( 'Maca_Backup_Pro_Format' ) ) {
		$checksum = Maca_Backup_Pro_Format::backup_checksum_label( $row );
		if ( '—' === $checksum ) {
			$checksum = '';
		}
	} elseif ( ! empty( $row->checksum ) ) {
		$checksum = (string) $row->checksum;
	}

	$ts = 0;
	if ( '' !== $when && '0000-00-00 00:00:00' !== $when ) {
		$parsed = strtotime( $when );
		$ts     = false !== $parsed ? (int) $parsed : 0;
	}

	return array(
		'id'               => (int) ( $row->id ?? 0 ),
		'type'             => (string) ( $row->type ?? '' ),
		'status'           => (string) ( $row->status ?? '' ),
		'size_bytes'       => (int) ( $row->size_bytes ?? 0 ),
		'db_size_bytes'    => (int) ( $row->db_size_bytes ?? 0 ),
		'duration'         => (int) ( $row->duration ?? 0 ),
		'file_count'       => (int) ( $row->file_count ?? 0 ),
		'parts'            => (int) ( $row->parts ?? 0 ),
		'storage'          => (string) ( $row->storage ?? '' ),
		'checksum'         => $checksum,
		'parent_backup_id' => (int) ( $row->parent_backup_id ?? 0 ),
		'started_at'       => $started,
		'finished_at'      => $finished,
		'created_at'       => $created,
		'datetime'         => $when,
		'timestamp'        => $ts,
		'error_message'    => (string) ( $row->error_message ?? '' ),
	);
}

/**
 * Map backup history list for Hub (newest first).
 *
 * @param int $limit Max rows (capped).
 * @return array<int, array<string, mixed>>
 */
function maca_backup_pro_hub_map_backups( int $limit = 100 ): array {
	$limit = (int) apply_filters( 'maca_backup_pro_hub_backups_limit', $limit );
	$limit = max( 1, min( 500, $limit ) );

	if ( ! class_exists( 'Maca_Backup_Pro_Backups_Table' ) ) {
		return array();
	}

	$rows = Maca_Backup_Pro_Backups_Table::recent( $limit );
	$out  = array();
	foreach ( $rows as $row ) {
		$mapped = maca_backup_pro_hub_map_backup( $row );
		if ( null !== $mapped ) {
			$out[] = $mapped;
		}
	}

	return $out;
}

/**
 * Map active job for Hub (trimmed progress fields).
 *
 * @param object|null $job Job row.
 * @return array<string, mixed>|null
 */
function maca_backup_pro_hub_map_active_job( $job ) {
	if ( ! is_object( $job ) ) {
		return null;
	}

	$job_type = (string) ( $job->job_type ?? 'backup' );
	if ( 'restore' === $job_type && class_exists( 'Maca_Backup_Pro_Restore_Engine' ) ) {
		$payload = Maca_Backup_Pro_Restore_Engine::status_payload( $job );
	} elseif ( class_exists( 'Maca_Backup_Pro_Backup_Engine' ) ) {
		$payload = Maca_Backup_Pro_Backup_Engine::status_payload( $job );
	} else {
		$payload = array(
			'progress'  => (int) ( $job->progress ?? 0 ),
			'step'      => (string) ( $job->step ?? '' ),
			'status'    => (string) ( $job->status ?? '' ),
			'job_id'    => (int) ( $job->id ?? 0 ),
			'backup_id' => (int) ( $job->backup_id ?? 0 ),
			'job_type'  => $job_type,
			'error'     => (string) ( $job->error_message ?? '' ),
		);
	}

	return array(
		'id'           => (int) ( $payload['job_id'] ?? $job->id ?? 0 ),
		'job_type'     => (string) ( $payload['job_type'] ?? $job_type ),
		'status'       => (string) ( $payload['status'] ?? '' ),
		'progress'     => (int) ( $payload['progress'] ?? 0 ),
		'step'         => (string) ( $payload['step'] ?? '' ),
		'backup_id'    => (int) ( $payload['backup_id'] ?? 0 ),
		'error'        => (string) ( $payload['error'] ?? '' ),
		'current_item' => (string) ( $payload['current_item'] ?? $payload['detail'] ?? '' ),
		'processed'    => (int) ( $payload['processed'] ?? 0 ),
		'total'        => (int) ( $payload['total'] ?? 0 ),
	);
}

/**
 * Map schedules for Hub monitoring.
 *
 * @return array<int, array<string, mixed>>
 */
function maca_backup_pro_hub_map_schedules() {
	if ( ! class_exists( 'Maca_Backup_Pro_Scheduler' ) ) {
		return array();
	}

	$schedules = Maca_Backup_Pro_Scheduler::all_schedules();
	$runs      = get_option( 'maca_backup_pro_schedule_runs', array() );
	if ( ! is_array( $runs ) ) {
		$runs = array();
	}

	$output = array();
	foreach ( $schedules as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$id      = (string) ( $row['id'] ?? '' );
		$enabled = ! empty( $row['enabled'] );
		$next    = $enabled ? Maca_Backup_Pro_Scheduler::next_occurrence_for_entry( $row ) : null;
		$run     = is_array( $runs[ $id ] ?? null ) ? $runs[ $id ] : array();
		$last_at = isset( $run['at'] ) ? (int) $run['at'] : null;

		$output[] = array(
			'id'              => $id,
			'label'           => (string) ( $row['label'] ?? '' ),
			'enabled'         => $enabled,
			'frequency'       => (string) ( $row['frequency'] ?? '' ),
			'frequency_label' => Maca_Backup_Pro_Scheduler::frequency_label( $row ),
			'backup_type'     => (string) ( $row['backup_type'] ?? 'full' ),
			'time_local'      => Maca_Backup_Pro_Scheduler::format_entry_time_local( $row ),
			'next_run_at'     => null !== $next ? (int) $next : null,
			'last_run_at'     => $last_at && $last_at > 0 ? $last_at : null,
		);
	}

	return $output;
}

/**
 * Hub monitoring status payload.
 *
 * @param int $backups_limit Max backups to include in the list.
 * @return array<string, mixed>
 */
function maca_backup_pro_hub_get_status( int $backups_limit = 100 ): array {
	$storage_id = (string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' );
	$provider   = class_exists( 'Maca_Backup_Pro_Storage_Registry' )
		? Maca_Backup_Pro_Storage_Registry::instance()->get( $storage_id )
		: null;
	$space      = $provider ? $provider->space_info() : null;

	$latest  = class_exists( 'Maca_Backup_Pro_Backups_Table' )
		? Maca_Backup_Pro_Backups_Table::latest_completed()
		: null;
	$failed  = class_exists( 'Maca_Backup_Pro_Backups_Table' )
		? Maca_Backup_Pro_Backups_Table::latest_failed()
		: null;
	$job     = null;
	if ( class_exists( 'Maca_Backup_Pro_Jobs_Table' ) ) {
		$job = Maca_Backup_Pro_Jobs_Table::active_for_ui( 'backup' )
			?: Maca_Backup_Pro_Jobs_Table::active_for_ui( 'restore' );
	}

	$next = class_exists( 'Maca_Backup_Pro_Scheduler' )
		? Maca_Backup_Pro_Scheduler::instance()->next_run()
		: null;

	$backups = maca_backup_pro_hub_map_backups( $backups_limit );

	return array(
		'installed'          => true,
		'version'            => defined( 'MACA_BACKUP_PRO_VERSION' ) ? MACA_BACKUP_PRO_VERSION : '',
		'hub_enabled'        => (bool) Maca_Backup_Pro_Settings::get( 'hub_enabled', false ),
		'timezone'           => function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '',
		'storage'            => array(
			'id'    => $storage_id,
			'label' => $provider ? (string) $provider->label() : $storage_id,
			'space' => is_array( $space ) ? array(
				'free'  => isset( $space['free'] ) ? (int) $space['free'] : null,
				'used'  => isset( $space['used'] ) ? (int) $space['used'] : null,
				'total' => isset( $space['total'] ) ? (int) $space['total'] : null,
			) : null,
		),
		'counts'             => array(
			'completed'        => class_exists( 'Maca_Backup_Pro_Backups_Table' )
				? Maca_Backup_Pro_Backups_Table::count_completed()
				: 0,
			'total'            => class_exists( 'Maca_Backup_Pro_Backups_Table' )
				? Maca_Backup_Pro_Backups_Table::count_all()
				: 0,
			'returned'         => count( $backups ),
			'total_size_bytes' => class_exists( 'Maca_Backup_Pro_Backups_Table' )
				? Maca_Backup_Pro_Backups_Table::total_size()
				: 0,
		),
		'next_backup_at'     => null !== $next ? (int) $next : null,
		'latest_backup'      => maca_backup_pro_hub_map_backup( $latest ),
		'last_failed_backup' => maca_backup_pro_hub_map_backup( $failed ),
		'backups'            => $backups,
		'active_job'         => maca_backup_pro_hub_map_active_job( $job ),
		'schedules'          => maca_backup_pro_hub_map_schedules(),
		'last_error'         => (string) get_option( 'maca_backup_pro_api_last_error', '' ),
	);
}
