<?php
/**
 * Admin AJAX handlers for long-running jobs.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every AJAX handler verifies via verify_ajax() or a background process token before reading $_POST.

/**
 * AJAX endpoints for backup/restore progress.
 */
class Maca_Backup_Pro_Ajax {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register actions.
	 *
	 * @return void
	 */
	public function boot(): void {
		$actions = array(
			'maca_backup_pro_start_backup'      => 'start_backup',
			'maca_backup_pro_process'           => 'process',
			'maca_backup_pro_job_status'        => 'job_status',
			'maca_backup_pro_cancel_job'        => 'cancel_job',
			'maca_backup_pro_start_restore'     => 'start_restore',
			'maca_backup_pro_preview'           => 'preview',
			'maca_backup_pro_browse_backup'     => 'browse_backup',
			'maca_backup_pro_smart_compare'     => 'smart_compare',
			'maca_backup_pro_compare_backups'   => 'compare_backups',
			'maca_backup_pro_smart_restore'     => 'smart_restore',
			'maca_backup_pro_delete_backup'     => 'delete_backup',
			'maca_backup_pro_verify_backup'     => 'verify_backup',
			'maca_backup_pro_staging'           => 'staging_restore',
			'maca_backup_pro_submit_support'    => 'submit_support',
			'maca_backup_pro_onboarding_finish' => 'onboarding_finish',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}

		add_action( 'wp_ajax_maca_backup_pro_bg_process', array( $this, 'bg_process' ) );
		add_action( 'wp_ajax_nopriv_maca_backup_pro_bg_process', array( $this, 'bg_process' ) );
	}

	/**
	 * Start backup.
	 *
	 * @return void
	 */
	public function start_backup(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		if ( ! Maca_Backup_Pro_Legal::is_accepted() ) {
			wp_send_json_error( array( 'message' => Maca_Backup_Pro_Legal::blocked_message() ) );
		}
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'full';
		$result = Maca_Backup_Pro_Backup_Engine::start( $type );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Process next chunk (legacy/manual accelerator).
	 *
	 * @return void
	 */
	public function process(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$job    = $job_id ? Maca_Backup_Pro_Jobs_Table::get( $job_id ) : null;
		$type   = $job ? (string) $job->job_type : 'backup';

		$result = ( 'restore' === $type )
			? Maca_Backup_Pro_Restore_Engine::process( $job_id ?: null )
			: Maca_Backup_Pro_Backup_Engine::process( $job_id ?: null );

		wp_send_json_success( $result );
	}

	/**
	 * Background process tick (token-authenticated, no browser required).
	 *
	 * @return void
	 */
	public function bg_process(): void {
		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! hash_equals( Maca_Backup_Pro_Scheduler::process_token(), $token ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		Maca_Backup_Pro_Scheduler::instance()->process_jobs();
		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * Job status (read-only UI poll; nudges background worker).
	 *
	 * @return void
	 */
	public function job_status(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$job    = $job_id ? Maca_Backup_Pro_Jobs_Table::get( $job_id ) : null;

		if ( ! $job ) {
			$job = Maca_Backup_Pro_Jobs_Table::active( 'backup' ) ?: Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		}

		if ( ! $job ) {
			wp_send_json_success(
				array(
					'done'     => true,
					'status'   => 'idle',
					'progress' => 0,
				)
			);
		}

		if ( in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
			// Short budget so the UI can refresh often; background loopback continues heavy work.
			Maca_Backup_Pro_Scheduler::instance()->process_jobs( 4 );
			Maca_Backup_Pro_Scheduler::instance()->spawn_loopback();
			$fresh = Maca_Backup_Pro_Jobs_Table::get( (int) $job->id );
			if ( $fresh ) {
				$job = $fresh;
			} else {
				Maca_Backup_Pro_Scheduler::instance()->schedule_process();
			}
		}

		if ( 'restore' === (string) $job->job_type ) {
			wp_send_json_success( Maca_Backup_Pro_Restore_Engine::status_payload( $job ) );
		}

		wp_send_json_success( Maca_Backup_Pro_Backup_Engine::status_payload( $job ) );
	}

	/**
	 * Cancel a running job.
	 *
	 * @return void
	 */
	public function cancel_job(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$job    = $job_id ? Maca_Backup_Pro_Jobs_Table::get( $job_id ) : null;

		if ( ! $job ) {
			$job = Maca_Backup_Pro_Jobs_Table::active( 'backup' ) ?: Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		}

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'No running job to stop.', 'maca-backup' ) ) );
		}

		$result = ( 'restore' === (string) $job->job_type )
			? Maca_Backup_Pro_Restore_Engine::cancel( (int) $job->id )
			: Maca_Backup_Pro_Backup_Engine::cancel( (int) $job->id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Start restore.
	 *
	 * @return void
	 */
	public function start_restore(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		if ( ! Maca_Backup_Pro_Legal::is_accepted() ) {
			wp_send_json_error( array( 'message' => Maca_Backup_Pro_Legal::blocked_message() ) );
		}
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$scope     = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'full';
		$paths     = isset( $_POST['paths'] ) ? (array) wp_unslash( $_POST['paths'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$paths     = array_map( 'sanitize_text_field', $paths );
		$database  = ! empty( $_POST['database'] );

		$options = array();
		if ( 'path' === $scope || ! empty( $paths ) ) {
			$scope                     = 'path';
			$options['selected_files'] = $paths;
			$options['restore_database'] = $database;
		}

		$result = Maca_Backup_Pro_Restore_Engine::start( $backup_id, $scope, $options );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Restore preview.
	 *
	 * @return void
	 */
	public function preview(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$scope     = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'full';
		$paths     = isset( $_POST['paths'] ) ? (array) wp_unslash( $_POST['paths'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$paths     = array_map( 'sanitize_text_field', $paths );
		$database  = ! empty( $_POST['database'] );
		if ( ! empty( $paths ) ) {
			$scope = 'path';
		}
		$result = Maca_Backup_Pro_Restore_Engine::preview( $backup_id, $scope, $paths, $database );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Browse backup tree (lazy).
	 *
	 * @return void
	 */
	public function browse_backup(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$prefix    = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '';
		$result    = Maca_Backup_Pro_Restore_Engine::browse( $backup_id, $prefix );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Smart Restore compare.
	 *
	 * @return void
	 */
	public function smart_compare(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$result    = Maca_Backup_Pro_Smart_Restore::compare( $backup_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Compare two backups against each other.
	 *
	 * @return void
	 */
	public function compare_backups(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$id_a   = isset( $_POST['backup_id_a'] ) ? (int) $_POST['backup_id_a'] : 0;
		$id_b   = isset( $_POST['backup_id_b'] ) ? (int) $_POST['backup_id_b'] : 0;
		$result = Maca_Backup_Pro_Smart_Restore::compare_backups( $id_a, $id_b );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Smart Restore selective.
	 *
	 * @return void
	 */
	public function smart_restore(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		if ( ! Maca_Backup_Pro_Legal::is_accepted() ) {
			wp_send_json_error( array( 'message' => Maca_Backup_Pro_Legal::blocked_message() ) );
		}
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$files     = isset( $_POST['files'] ) ? (array) wp_unslash( $_POST['files'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$files     = array_map( 'sanitize_text_field', $files );
		$database  = ! empty( $_POST['database'] );
		$result    = Maca_Backup_Pro_Smart_Restore::restore_selected( $backup_id, $files, $database );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Delete backup.
	 *
	 * @return void
	 */
	public function delete_backup(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$ok        = Maca_Backup_Pro_Backup_Engine::delete_backup( $backup_id );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete backup.', 'maca-backup' ) ) );
		}
		wp_send_json_success( array( 'deleted' => $backup_id ) );
	}

	/**
	 * Run automatic verification (temp restore smoke test).
	 *
	 * @return void
	 */
	public function verify_backup(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		$result    = Maca_Backup_Pro_Staging::verify( $backup_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Staging restore to a temporary directory.
	 *
	 * @return void
	 */
	public function staging_restore(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		$backup_id = isset( $_POST['backup_id'] ) ? (int) $_POST['backup_id'] : 0;
		// Optional relative folder name only — never an absolute filesystem path.
		$subdir = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
		$result = Maca_Backup_Pro_Staging::restore( $backup_id, $subdir );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Submit support ticket (Fluent Support on maca.se, with e-mail fallback).
	 *
	 * Always returns JSON — never a blank admin-ajax body that whitescreens a full navigation.
	 *
	 * @return void
	 */
	public function submit_support(): void {
		Maca_Backup_Pro_Security::verify_ajax();

		// Drop accidental notices/warnings so the client receives clean JSON.
		if ( ob_get_length() ) {
			ob_clean();
		}

		try {
			$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
			$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
			$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$include = ! empty( $_POST['include_system_info'] );

			if ( $include ) {
				$message = trim( $message ) . "\n\n" . Maca_Backup_Pro_Support::build_system_info_block();
			}

			$result = Maca_Backup_Pro_Support::submit_ticket(
				array(
					'subject' => $subject,
					'message' => $message,
					'email'   => $email,
					'name'    => $name,
				)
			);

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'message' => __(
						'Thank you! Your request has been sent. We will reply to the email address above — check your inbox (and spam folder).',
						'maca-backup'
					),
				)
			);
		} catch ( Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => __(
						'Something went wrong. Please try again or email support@maca.se.',
						'maca-backup'
					),
				)
			);
		}
	}

	/**
	 * Apply first-run onboarding wizard choices (storage, optional schedule, optional backup).
	 *
	 * @return void
	 */
	public function onboarding_finish(): void {
		Maca_Backup_Pro_Security::verify_ajax();
		if ( ! Maca_Backup_Pro_Legal::is_accepted() ) {
			wp_send_json_error( array( 'message' => Maca_Backup_Pro_Legal::blocked_message() ) );
		}

		$backup_type = isset( $_POST['backup_type'] ) ? sanitize_key( wp_unslash( $_POST['backup_type'] ) ) : 'full';
		if ( ! in_array( $backup_type, array( 'full', 'database', 'files' ), true ) ) {
			$backup_type = 'full';
		}

		$storage_id = isset( $_POST['storage_provider'] ) ? sanitize_key( wp_unslash( $_POST['storage_provider'] ) ) : 'local';
		$provider   = Maca_Backup_Pro_Storage_Registry::instance()->get( $storage_id );
		if ( ! $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown storage provider.', 'maca-backup' ) ) );
		}
		if ( 'local' !== $storage_id && ! $provider->is_configured() ) {
			wp_send_json_error(
				array(
					'message'     => __( 'Configure this storage destination first, or choose Local storage.', 'maca-backup' ),
					'storage_url' => Maca_Backup_Pro_Admin::tab_url( 'storage' ),
				)
			);
		}

		$mode = isset( $_POST['run_mode'] ) ? sanitize_key( wp_unslash( $_POST['run_mode'] ) ) : 'now';
		if ( ! in_array( $mode, array( 'now', 'schedule', 'both' ), true ) ) {
			$mode = 'now';
		}

		$current = (string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' );
		if ( $storage_id !== $current ) {
			Maca_Backup_Pro_Settings::update( array( 'storage_provider' => $storage_id ) );
		}

		$schedule_id = null;
		if ( in_array( $mode, array( 'schedule', 'both' ), true ) ) {
			$freq = isset( $_POST['schedule_frequency'] ) ? sanitize_key( wp_unslash( $_POST['schedule_frequency'] ) ) : 'daily';
			if ( ! in_array( $freq, array( 'daily', 'weekly' ), true ) ) {
				$freq = 'daily';
			}

			$local_hour   = isset( $_POST['schedule_hour_local'] ) ? absint( $_POST['schedule_hour_local'] ) : 3;
			$local_minute = isset( $_POST['schedule_minute_local'] ) ? absint( $_POST['schedule_minute_local'] ) : 0;
			$local_hour   = min( 23, $local_hour );
			$local_minute = (int) ( round( min( 59, $local_minute ) / 5 ) * 5 ) % 60;
			$local_weekday = isset( $_POST['schedule_weekday'] ) ? absint( $_POST['schedule_weekday'] ) % 7 : 1;

			$utc = Maca_Backup_Pro_Scheduler::local_to_utc( $local_hour, $local_minute, $local_weekday, 1, $freq );

			$saved = Maca_Backup_Pro_Scheduler::upsert_schedule(
				array(
					'id'          => '',
					'label'       => '',
					'enabled'     => true,
					'frequency'   => $freq,
					'time_utc'    => sprintf( '%02d:%02d', $utc['hour'], $utc['minute'] ),
					'weekday'     => $utc['weekday'],
					'dom'         => $utc['dom'],
					'backup_type' => $backup_type,
				)
			);
			$schedule_id = (string) ( $saved['id'] ?? '' );
		}

		$job_id = null;
		if ( in_array( $mode, array( 'now', 'both' ), true ) ) {
			$result = Maca_Backup_Pro_Backup_Engine::start( $backup_type );
			if ( is_wp_error( $result ) ) {
				// Still mark onboarding done if schedule was created; surface the backup error.
				Maca_Backup_Pro_Admin::mark_onboarding_done();
				wp_send_json_error(
					array(
						'message'     => $result->get_error_message(),
						'schedule_id' => $schedule_id,
						'partial'     => true,
					)
				);
			}
			$job_id = isset( $result['job_id'] ) ? (int) $result['job_id'] : null;
		}

		Maca_Backup_Pro_Admin::mark_onboarding_done();

		wp_send_json_success(
			array(
				'job_id'      => $job_id,
				'schedule_id' => $schedule_id,
				'run_mode'    => $mode,
				'message'     => __( 'Setup complete.', 'maca-backup' ),
			)
		);
	}
}
