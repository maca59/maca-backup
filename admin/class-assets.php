<?php
/**
 * Admin assets.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin CSS/JS on plugin screens.
 */
class Maca_Backup_Pro_Assets {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_plugins_screen' ) );
	}

	/**
	 * Scripts on the Plugins screen (deactivation feedback modal).
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_plugins_screen( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		require_once MACA_BACKUP_PRO_PATH . 'includes/deactivation-feedback.php';

		wp_enqueue_style(
			'maca-backup-pro-admin-plugins',
			MACA_BACKUP_PRO_URL . 'assets/css/admin-plugins.css',
			array(),
			MACA_BACKUP_PRO_VERSION
		);

		wp_enqueue_script(
			'maca-backup-pro-admin-plugins',
			MACA_BACKUP_PRO_URL . 'assets/js/admin-plugins.js',
			array(),
			MACA_BACKUP_PRO_VERSION,
			true
		);

		$reasons = array();
		foreach ( maca_backup_pro_deactivation_feedback_reasons() as $id => $label ) {
			$reasons[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}

		wp_localize_script(
			'maca-backup-pro-admin-plugins',
			'macaBackupProPlugins',
			array(
				'pluginSlug'         => MACA_BACKUP_PRO_BASENAME,
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'maca_backup_pro_deactivation_feedback' ),
				'modalTitle'         => __( 'Innan du avaktiverar maca BackUp', 'maca-backup-pro' ),
				'modalIntro'         => __( 'Hjälp oss förbättra — vad är huvudorsaken till att du avaktiverar?', 'maca-backup-pro' ),
				'detailsPlaceholder' => __( 'Berätta gärna mer (valfritt)', 'maca-backup-pro' ),
				'cancelLabel'        => __( 'Avbryt', 'maca-backup-pro' ),
				'skipLabel'          => __( 'Hoppa över och avaktivera', 'maca-backup-pro' ),
				'submitLabel'        => __( 'Skicka feedback och avaktivera', 'maca-backup-pro' ),
				'reasons'            => $reasons,
			)
		);
	}

	/**
	 * Enqueue on maca BackUp pages.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'maca-backup' ) ) {
			return;
		}

		wp_enqueue_style(
			'maca-backup-pro-admin',
			MACA_BACKUP_PRO_URL . 'assets/css/admin.css',
			array(),
			MACA_BACKUP_PRO_VERSION
		);

		wp_enqueue_script(
			'maca-backup-pro-admin',
			MACA_BACKUP_PRO_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MACA_BACKUP_PRO_VERSION,
			true
		);

		wp_localize_script(
			'maca-backup-pro-admin',
			'macaBackupPro',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'maca_backup_pro_ajax' ),
				'restUrl'       => esc_url_raw( rest_url( 'maca-backup-pro/v1' ) ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'activeJob'     => self::active_job_for_js(),
				'legalAccepted' => Maca_Backup_Pro_Legal::is_accepted(),
				'supportUrl'    => Maca_Backup_Pro_Legal::admin_support_url( 'accept' ),
				'i18n'          => array(
					'starting'     => __( 'Starting…', 'maca-backup-pro' ),
					'running'      => __( 'Running in background…', 'maca-backup-pro' ),
					'elapsed'      => __( 'Elapsed', 'maca-backup-pro' ),
					'done'         => __( 'Completed', 'maca-backup-pro' ),
					'failed'       => __( 'Failed', 'maca-backup-pro' ),
					'cancelled'    => __( 'Cancelled', 'maca-backup-pro' ),
					'confirmDel'   => __( 'Delete this backup permanently?', 'maca-backup-pro' ),
					'confirmRes'   => __( 'Restore will overwrite selected files/database. Continue?', 'maca-backup-pro' ),
					'confirmStop'  => __( 'Stop the running job? Partial files will be removed.', 'maca-backup-pro' ),
					'selectBackup' => __( 'Select a backup first.', 'maca-backup-pro' ),
					'compareNeedTwo' => __( 'Select two different backups to compare.', 'maca-backup-pro' ),
					'compareRunning' => __( 'Comparing…', 'maca-backup-pro' ),
					'compareFiles'   => __( 'Files in inventory', 'maca-backup-pro' ),
					'compareArchive' => __( 'Archive size', 'maca-backup-pro' ),
					'compareContent' => __( 'Uncompressed content', 'maca-backup-pro' ),
					'compareSame'    => __( 'Identical paths', 'maca-backup-pro' ),
					'compareOnlyA'   => __( 'Only in A', 'maca-backup-pro' ),
					'compareOnlyB'   => __( 'Only in B', 'maca-backup-pro' ),
					'compareMismatch'=> __( 'Size / CRC mismatch', 'maca-backup-pro' ),
					/* translators: %d: number of additional paths not listed */
					'compareMore'    => __( '…and %d more', 'maca-backup-pro' ),
					'testing'       => __( 'Running test restore…', 'maca-backup-pro' ),
					'testPass'     => __( 'Test restore passed — archive can be restored.', 'maca-backup-pro' ),
					'testFail'     => __( 'Test restore found issues.', 'maca-backup-pro' ),
					'checkArchive' => __( 'Archive readable', 'maca-backup-pro' ),
					'checkManifest'=> __( 'Manifest present', 'maca-backup-pro' ),
					'checkDatabase'=> __( 'Database dump OK', 'maca-backup-pro' ),
					'checkFiles'   => __( 'Files extracted', 'maca-backup-pro' ),
					'checkSkip'    => __( 'Not applicable', 'maca-backup-pro' ),
					'legalRequired'=> Maca_Backup_Pro_Legal::blocked_message(),
					'supportSending'    => __( 'Sending…', 'maca-backup-pro' ),
					'supportSuccess'    => __( 'Thank you! Your request has been sent. We will reply to the email address above — check your inbox (and spam folder).', 'maca-backup-pro' ),
					'supportError'      => __( 'Something went wrong. Please try again or email support@maca.se.', 'maca-backup-pro' ),
					'supportValidation' => __( 'Please fill in subject, message, and email.', 'maca-backup-pro' ),
				),
			)
		);
	}

	/**
	 * Active job payload for admin JS (resume progress UI).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function active_job_for_js(): ?array {
		Maca_Backup_Pro_Jobs_Table::reap_stale();

		$jobs = Maca_Backup_Pro_Jobs_Table::active_all( 'backup' );
		$job  = $jobs[0] ?? null;
		if ( ! $job ) {
			$job = Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		}
		if ( ! $job ) {
			return null;
		}

		$status = (string) $job->status;
		if ( ! in_array( $status, array( 'pending', 'running' ), true ) ) {
			return null;
		}

		$state   = json_decode( (string) $job->state, true );
		$started = is_array( $state ) ? (int) ( $state['started'] ?? 0 ) : 0;

		return array(
			'id'        => (int) $job->id,
			'job_type'  => (string) $job->job_type,
			'status'    => $status,
			'progress'  => (int) $job->progress,
			'step'      => (string) $job->step,
			'backup_id' => (int) $job->backup_id,
			'started'   => $started,
			'parallel'  => count( $jobs ) > 1 ? count( $jobs ) : ( 'backup' === (string) $job->job_type ? count( $jobs ) : 0 ),
		);
	}
}
