<?php
/**
 * Admin menu and page routing.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Admin form handlers only run after verify_admin_nonce() in handle_posts().

/**
 * Top-level maca BackUp admin.
 */
class Maca_Backup_Pro_Admin {

	public const MENU_SLUG = 'maca-backup';

	/** Previous menu slug — bookmarks and screen IDs still redirect here. */
	public const LEGACY_MENU_SLUG = 'maca-backup-pro';

	/** Option key for first-run onboarding dismissal (Skip for now). */
	public const ONBOARDING_DISMISSED_OPTION = 'maca_backup_pro_onboarding_dismissed';

	/** Option key set when the onboarding wizard finishes successfully. */
	public const ONBOARDING_DONE_OPTION = 'maca_backup_pro_onboarding_done';

	/** User meta key: dismissed failed backup notice (stores backup ID). */
	public const FAILED_NOTICE_DISMISS_META = 'maca_backup_pro_dismiss_failed_notice';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_pages' ), 1 );
		add_action( 'admin_init', array( $this, 'handle_posts' ), 1 );
		add_action( 'admin_init', array( $this, 'handle_failed_notice_dismiss' ) );
		add_action( 'admin_post_maca_backup_download', array( $this, 'handle_download_post' ) );
		add_action( 'admin_notices', array( $this, 'legal_admin_notice' ) );
		add_action( 'admin_notices', array( $this, 'failed_backup_admin_notice' ) );
		add_filter( 'plugin_action_links_' . MACA_BACKUP_PRO_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Signed admin-post URL for downloading a completed backup.
	 *
	 * @param int $backup_id Backup row ID.
	 * @return string
	 */
	public static function download_url( int $backup_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=maca_backup_download&backup_id=' . $backup_id ),
			Maca_Backup_Pro_Security::NONCE_ACTION
		);
	}

	/**
	 * admin-post.php download entry (lighter than a full admin page POST).
	 *
	 * @return void
	 */
	public function handle_download_post(): void {
		Maca_Backup_Pro_Security::verify_admin_nonce();
		$this->download_backup();
	}

	/**
	 * Built-in Dashicon for the WP admin sidebar.
	 *
	 * @return string
	 */
	public static function menu_icon(): string {
		return 'dashicons-backup';
	}

	/**
	 * Available admin tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'dashboard' => __( 'Dashboard', 'maca-backup' ),
			'backups'   => __( 'Backups', 'maca-backup' ),
			'restore'   => __( 'Restore', 'maca-backup' ),
			'smart'     => __( 'Smart Restore', 'maca-backup' ),
			'schedule'  => __( 'Schedule', 'maca-backup' ),
			'storage'   => __( 'Storage', 'maca-backup' ),
			'logs'      => __( 'Logs', 'maca-backup' ),
			'settings'  => __( 'Settings', 'maca-backup' ),
			'help'      => __( 'Help', 'maca-backup' ),
			'support'   => __( 'Support', 'maca-backup' ),
		);
	}

	/**
	 * URL for a tab (preserves useful query args when provided).
	 *
	 * @param string               $tab  Tab slug.
	 * @param array<string, mixed> $args Extra query args.
	 * @return string
	 */
	public static function tab_url( string $tab, array $args = array() ): string {
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		$query = array_merge(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			$args
		);
		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Current tab from request.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = self::tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'dashboard';
	}

	/**
	 * Legacy submenu slug → tab map.
	 *
	 * @return array<string, string>
	 */
	private static function legacy_page_map(): array {
		$legacy = self::LEGACY_MENU_SLUG;
		return array(
			$legacy                => 'dashboard',
			$legacy . '-backups'   => 'backups',
			$legacy . '-restore'   => 'restore',
			$legacy . '-smart'     => 'smart',
			$legacy . '-schedule'  => 'schedule',
			$legacy . '-storage'   => 'storage',
			$legacy . '-logs'      => 'logs',
			$legacy . '-settings'  => 'settings',
		);
	}

	/**
	 * Register single top-level menu (tabs handle navigation).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			'maca BackUp',
			'maca BackUp',
			Maca_Backup_Pro_Security::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_app' ),
			self::menu_icon(),
			59
		);

		// Single submenu entry with same slug avoids a duplicate "Dashboard" clone.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'maca-backup' ),
			__( 'Dashboard', 'maca-backup' ),
			Maca_Backup_Pro_Security::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_app' )
		);
		// Keep only the top-level item in the WP sidebar; in-plugin tabs handle navigation.
		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );

		// Hidden legacy pages so old bookmarks still resolve (then redirect).
		foreach ( self::legacy_page_map() as $slug => $tab ) {
			add_submenu_page(
				'options.php',
				'maca BackUp',
				'',
				Maca_Backup_Pro_Security::CAPABILITY,
				$slug,
				static function () use ( $tab ): void {
					$args = array();
					if ( ! empty( $_GET['backup_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$args['backup_id'] = absint( $_GET['backup_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					}
					if ( ! empty( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$args['edit'] = sanitize_key( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					}
					wp_safe_redirect( Maca_Backup_Pro_Admin::tab_url( $tab, $args ) );
					exit;
				}
			);
		}
	}

	/**
	 * Redirect bookmarks of old submenu URLs to tabbed URLs.
	 *
	 * @return void
	 */
	public function redirect_legacy_pages(): void {
		if ( empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = self::legacy_page_map();
		if ( ! isset( $map[ $page ] ) ) {
			return;
		}

		$args = array();
		if ( ! empty( $_GET['tab'] ) && self::LEGACY_MENU_SLUG === $page ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( self::tabs()[ $tab ] ) ) {
				$map[ $page ] = $tab;
			}
		}
		if ( ! empty( $_GET['backup_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['backup_id'] = absint( $_GET['backup_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['edit'] = sanitize_key( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		wp_safe_redirect( self::tab_url( $map[ $page ], $args ) );
		exit;
	}

	/**
	 * Handle form posts.
	 *
	 * @return void
	 */
	public function handle_posts(): void {
		if ( empty( $_POST['maca_backup_pro_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		Maca_Backup_Pro_Security::verify_admin_nonce();

		$action = sanitize_key( wp_unslash( $_POST['maca_backup_pro_action'] ) );

		switch ( $action ) {
			case 'save_settings':
				$this->save_settings();
				break;
			case 'save_schedule':
				$this->save_schedule();
				break;
			case 'delete_schedule':
				$this->delete_schedule();
				break;
			case 'toggle_schedule':
				$this->toggle_schedule();
				break;
			case 'save_storage':
				$this->save_storage();
				break;
			case 'download_backup':
				$this->download_backup();
				break;
			case 'import_backup':
				$this->import_backup();
				break;
			case 'accept_legal':
				$this->accept_legal();
				break;
			case 'dismiss_onboarding':
				$this->dismiss_onboarding();
				break;
			case 'send_test_email':
				$this->send_test_email();
				break;
		}
	}

	/**
	 * Whether the dashboard first-run onboarding wizard should show.
	 *
	 * Shown after legal acceptance until the wizard is finished or skipped.
	 * Also hidden when the site already has completed backups or schedules
	 * (covers upgrades from the old two-button card).
	 *
	 * @return bool
	 */
	public static function should_show_onboarding(): bool {
		if ( ! Maca_Backup_Pro_Legal::is_accepted() ) {
			return false;
		}
		if ( (bool) get_option( self::ONBOARDING_DONE_OPTION, false ) ) {
			return false;
		}
		if ( (bool) get_option( self::ONBOARDING_DISMISSED_OPTION, false ) ) {
			return false;
		}
		if ( Maca_Backup_Pro_Backups_Table::count_completed() > 0 ) {
			return false;
		}
		if ( ! empty( Maca_Backup_Pro_Scheduler::all_schedules() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Persist onboarding dismissal (Skip for now).
	 *
	 * @return void
	 */
	public static function mark_onboarding_dismissed(): void {
		update_option( self::ONBOARDING_DISMISSED_OPTION, 1, false );
	}

	/**
	 * Persist successful onboarding wizard completion.
	 *
	 * @return void
	 */
	public static function mark_onboarding_done(): void {
		update_option( self::ONBOARDING_DONE_OPTION, 1, false );
		update_option( self::ONBOARDING_DISMISSED_OPTION, 1, false );
	}

	/**
	 * Handle "Skip for now" from the dashboard onboarding wizard.
	 *
	 * @return void
	 */
	private function dismiss_onboarding(): void {
		self::mark_onboarding_dismissed();
		wp_safe_redirect( self::tab_url( 'dashboard' ) );
		exit;
	}

	/**
	 * Provider list for the onboarding wizard UI.
	 *
	 * @return array<int, array{id: string, label: string, configured: bool}>
	 */
	public static function onboarding_providers(): array {
		$list = array();
		foreach ( Maca_Backup_Pro_Storage_Registry::instance()->all() as $id => $provider ) {
			$list[] = array(
				'id'         => (string) $id,
				'label'      => $provider->label(),
				'configured' => $provider->is_configured(),
			);
		}
		return $list;
	}

	/**
	 * Default local schedule time for onboarding (mirrors schedule editor defaults).
	 *
	 * @return array{hour: int, minute: int, weekday: int}
	 */
	public static function onboarding_schedule_defaults(): array {
		$local = Maca_Backup_Pro_Scheduler::utc_to_local( 3, 0, 1, 1, 'daily' );
		return array(
			'hour'    => (int) $local['hour'],
			'minute'  => (int) ( round( (int) $local['minute'] / 5 ) * 5 ) % 60,
			'weekday' => (int) $local['weekday'],
		);
	}

	/**
	 * Persist Terms + Privacy acceptance.
	 *
	 * @return void
	 */
	private function accept_legal(): void {
		$accept_terms   = ! empty( $_POST['accept_terms'] );
		$accept_privacy = ! empty( $_POST['accept_privacy'] );

		if ( ! $accept_terms || ! $accept_privacy ) {
			add_settings_error(
				'maca_backup_pro',
				'legal_missing',
				__( 'You must accept both the Terms of Use and the Privacy Policy to continue.', 'maca-backup' ),
				'error'
			);
			return;
		}

		if ( ! Maca_Backup_Pro_Legal::accept( get_current_user_id() ) ) {
			add_settings_error(
				'maca_backup_pro',
				'legal_fail',
				__( 'Could not save your acceptance. Please try again.', 'maca-backup' ),
				'error'
			);
			return;
		}

		add_settings_error(
			'maca_backup_pro',
			'legal_ok',
			__( 'Thank you. You can now run backups and restores.', 'maca-backup' ),
			'success'
		);

		wp_safe_redirect( self::tab_url( 'support' ) );
		exit;
	}

	/**
	 * Notice on plugin screens when legal acceptance is pending.
	 *
	 * @return void
	 */
	public function legal_admin_notice(): void {
		if ( ! Maca_Backup_Pro_Security::can_manage() || Maca_Backup_Pro_Legal::is_accepted() ) {
			return;
		}

		if ( ! $this->is_plugin_admin_screen() ) {
			return;
		}

		$url = Maca_Backup_Pro_Legal::admin_support_url( 'accept' );
		echo '<div class="notice notice-warning"><p>';
		/* translators: %s: Support tab URL */
		$notice = __( 'maca BackUp requires acceptance of the Terms and Privacy Policy before backup or restore. <a href="%s">Review and accept</a>.', 'maca-backup' );
		printf(
			wp_kses(
				$notice,
				array(
					'a' => array(
						'href' => true,
					),
				)
			),
			esc_url( $url )
		);
		echo '</p></div>';
	}

	/**
	 * Dismiss failed-backup admin notice for the current user.
	 *
	 * @return void
	 */
	public function handle_failed_notice_dismiss(): void {
		if ( empty( $_GET['maca_bp_dismiss_fail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! Maca_Backup_Pro_Security::can_manage() ) {
			return;
		}

		check_admin_referer( 'maca_bp_dismiss_fail' );
		$backup_id = absint( wp_unslash( $_GET['maca_bp_dismiss_fail'] ) );
		if ( $backup_id > 0 ) {
			update_user_meta( get_current_user_id(), self::FAILED_NOTICE_DISMISS_META, $backup_id );
		}

		$redirect = wp_get_referer();
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = self::tab_url( 'logs' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Admin notice when the latest backup failed and no newer success exists.
	 *
	 * @return void
	 */
	public function failed_backup_admin_notice(): void {
		if ( ! Maca_Backup_Pro_Security::can_manage() ) {
			return;
		}

		// Plugin screens use the in-header fail banner instead.
		if ( $this->is_plugin_admin_screen() ) {
			return;
		}

		$failed = Maca_Backup_Pro_Backups_Table::unresolved_failed();
		if ( ! $failed ) {
			return;
		}

		$dismissed = (int) get_user_meta( get_current_user_id(), self::FAILED_NOTICE_DISMISS_META, true );
		if ( $dismissed === (int) $failed->id ) {
			return;
		}

		$when = Maca_Backup_Pro_Format::datetime_local(
			! empty( $failed->finished_at ) ? (string) $failed->finished_at : (string) $failed->created_at
		);
		$error = trim( (string) ( $failed->error_message ?? '' ) );
		$logs  = self::tab_url( 'logs' );
		$backups = self::tab_url( 'backups' );
		$dismiss = wp_nonce_url(
			add_query_arg( 'maca_bp_dismiss_fail', (int) $failed->id ),
			'maca_bp_dismiss_fail'
		);

		echo '<div class="notice notice-error maca-bp-fail-notice"><p>';
		echo '<strong>' . esc_html__( 'maca BackUp:', 'maca-backup' ) . '</strong> ';
		printf(
			/* translators: 1: backup type, 2: datetime */
			esc_html__( 'A %1$s backup failed on %2$s.', 'maca-backup' ),
			esc_html( (string) $failed->type ),
			esc_html( $when )
		);
		if ( '' !== $error ) {
			echo ' ';
			echo esc_html( $error );
		}
		echo '</p><p>';
		echo '<a class="button button-small" href="' . esc_url( $logs ) . '">' . esc_html__( 'View logs', 'maca-backup' ) . '</a> ';
		echo '<a class="button button-small" href="' . esc_url( $backups ) . '">' . esc_html__( 'Backups', 'maca-backup' ) . '</a> ';
		echo '<a class="button-link" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'maca-backup' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Whether the current request is a maca BackUp admin screen.
	 *
	 * @return bool
	 */
	private function is_plugin_admin_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if (
			'' !== $page
			&& (
				self::MENU_SLUG === $page
				|| self::LEGACY_MENU_SLUG === $page
				|| str_starts_with( $page, self::MENU_SLUG . '-' )
				|| str_starts_with( $page, self::LEGACY_MENU_SLUG . '-' )
			)
		) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && is_string( $screen->id ) && false !== strpos( $screen->id, 'maca-backup' );
	}

	/**
	 * Save general settings.
	 *
	 * @return void
	 */
	private function save_settings(): void {
		$input = array(
			'retention_count'       => isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : 10,
			'zip_split_mb'          => isset( $_POST['zip_split_mb'] ) ? absint( $_POST['zip_split_mb'] ) : 400,
			'chunk_files'           => isset( $_POST['chunk_files'] ) ? absint( $_POST['chunk_files'] ) : 400,
			'chunk_tables'          => isset( $_POST['chunk_tables'] ) ? absint( $_POST['chunk_tables'] ) : 5,
			'encrypt_backups'       => ! empty( $_POST['encrypt_backups'] ),
			'pre_update_backup'     => ! empty( $_POST['pre_update_backup'] ),
			'pre_update_retention'  => isset( $_POST['pre_update_retention'] ) ? absint( $_POST['pre_update_retention'] ) : 5,
			'hub_enabled'           => ! empty( $_POST['hub_enabled'] ),
			'email_enabled'         => ! empty( $_POST['email_enabled'] ),
			'email_recipients'      => isset( $_POST['email_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['email_recipients'] ) ) : '',
			'email_on_success'      => ! empty( $_POST['email_on_success'] ),
			'email_on_failure'      => ! empty( $_POST['email_on_failure'] ),
			'email_on_restore_ok'   => ! empty( $_POST['email_on_restore_ok'] ),
			'email_on_restore_fail' => ! empty( $_POST['email_on_restore_fail'] ),
			'exclude_paths'         => isset( $_POST['exclude_paths'] )
				? array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['exclude_paths'] ) ) ) ) )
				: array(),
		);

		if ( isset( $_POST['backup_passphrase'] ) ) {
			$pp = (string) wp_unslash( $_POST['backup_passphrase'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $pp ) {
				$input['backup_passphrase'] = sanitize_text_field( $pp );
			}
		}

		Maca_Backup_Pro_Settings::update( $input );
		add_settings_error( 'maca_backup_pro', 'saved', __( 'Settings saved.', 'maca-backup' ), 'success' );
	}

	/**
	 * Send a test notification email from Settings.
	 *
	 * @return void
	 */
	private function send_test_email(): void {
		$result = Maca_Backup_Pro_Mailer::send_test();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'maca_backup_pro', 'test_email', $result->get_error_message(), 'error' );
			return;
		}

		$recipients = Maca_Backup_Pro_Mailer::recipient_list();
		add_settings_error(
			'maca_backup_pro',
			'test_email',
			sprintf(
				/* translators: %s: comma-separated email addresses */
				__( 'Test email sent to %s. Check inbox and spam.', 'maca-backup' ),
				implode( ', ', $recipients )
			),
			'success'
		);
	}

	/**
	 * Create or update a schedule entry.
	 * Form sends local time; we convert to UTC for storage.
	 *
	 * @return void
	 */
	private function save_schedule(): void {
		$local_hour   = isset( $_POST['schedule_hour_local'] ) ? absint( $_POST['schedule_hour_local'] ) : 3;
		$local_minute = isset( $_POST['schedule_minute_local'] ) ? absint( $_POST['schedule_minute_local'] ) : 0;
		$local_hour   = min( 23, $local_hour );
		$local_minute = (int) ( round( min( 59, $local_minute ) / 5 ) * 5 ) % 60;

		$freq = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : 'daily';
		if ( 'manual' === $freq ) {
			$freq = 'daily';
		}

		$local_weekday = isset( $_POST['schedule_weekday'] ) ? absint( $_POST['schedule_weekday'] ) % 7 : 1;
		$local_dom     = max( 1, min( 28, isset( $_POST['schedule_dom'] ) ? absint( $_POST['schedule_dom'] ) : 1 ) );

		$utc = Maca_Backup_Pro_Scheduler::local_to_utc( $local_hour, $local_minute, $local_weekday, $local_dom, $freq );

		$entry = array(
			'id'             => isset( $_POST['schedule_id'] ) ? sanitize_key( wp_unslash( $_POST['schedule_id'] ) ) : '',
			'label'          => isset( $_POST['schedule_label'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_label'] ) ) : '',
			'enabled'        => ! empty( $_POST['schedule_enabled'] ),
			'frequency'      => $freq,
			'time_utc'       => sprintf( '%02d:%02d', $utc['hour'], $utc['minute'] ),
			'interval_hours' => isset( $_POST['interval_hours'] ) ? absint( $_POST['interval_hours'] ) : 4,
			'weekday'        => $utc['weekday'],
			'dom'            => $utc['dom'],
			'custom_cron'    => isset( $_POST['custom_cron'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_cron'] ) ) : '',
			'backup_type'    => isset( $_POST['backup_type'] ) ? sanitize_key( wp_unslash( $_POST['backup_type'] ) ) : 'full',
			'email_mode'     => isset( $_POST['schedule_email_mode'] ) ? sanitize_key( wp_unslash( $_POST['schedule_email_mode'] ) ) : 'inherit',
		);

		$saved = Maca_Backup_Pro_Scheduler::upsert_schedule( $entry );
		add_settings_error(
			'maca_backup_pro',
			'schedule',
			sprintf(
				/* translators: %s: schedule label */
				__( 'Schedule “%s” saved.', 'maca-backup' ),
				(string) ( $saved['label'] ?: $saved['id'] )
			),
			'success'
		);
	}

	/**
	 * Delete a schedule entry.
	 *
	 * @return void
	 */
	private function delete_schedule(): void {
		$id = isset( $_POST['schedule_id'] ) ? sanitize_key( wp_unslash( $_POST['schedule_id'] ) ) : '';
		if ( '' === $id || ! Maca_Backup_Pro_Scheduler::delete_schedule( $id ) ) {
			add_settings_error( 'maca_backup_pro', 'schedule', __( 'Could not delete schedule.', 'maca-backup' ), 'error' );
			return;
		}
		add_settings_error( 'maca_backup_pro', 'schedule', __( 'Schedule deleted.', 'maca-backup' ), 'success' );
	}

	/**
	 * Enable/disable a schedule.
	 *
	 * @return void
	 */
	private function toggle_schedule(): void {
		$id      = isset( $_POST['schedule_id'] ) ? sanitize_key( wp_unslash( $_POST['schedule_id'] ) ) : '';
		$enabled = ! empty( $_POST['schedule_enabled'] );
		$saved   = Maca_Backup_Pro_Scheduler::set_enabled( $id, $enabled );
		if ( ! $saved ) {
			add_settings_error( 'maca_backup_pro', 'schedule', __( 'Schedule not found.', 'maca-backup' ), 'error' );
			return;
		}
		add_settings_error(
			'maca_backup_pro',
			'schedule',
			$enabled
				? __( 'Schedule enabled.', 'maca-backup' )
				: __( 'Schedule disabled.', 'maca-backup' ),
			'success'
		);
	}

	/**
	 * Save storage settings.
	 *
	 * @return void
	 */
	private function save_storage(): void {
		$provider = isset( $_POST['storage_provider'] ) ? sanitize_key( wp_unslash( $_POST['storage_provider'] ) ) : 'local';
		$storage  = array();

		if ( isset( $_POST['storage'] ) && is_array( $_POST['storage'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = wp_unslash( $_POST['storage'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( $raw as $pid => $fields ) {
				if ( ! is_array( $fields ) ) {
					continue;
				}
				$pid = sanitize_key( $pid );
				foreach ( $fields as $key => $value ) {
					$key = sanitize_key( $key );
					if ( in_array( $key, array( 'pass', 'key', 'client_secret', 'refresh_token', 'access_token', 'secret_key' ), true ) ) {
						$value = (string) $value;
						if ( '' === $value ) {
							continue; // Keep existing secret.
						}
						$storage[ $pid ][ $key ] = sanitize_textarea_field( $value );
					} elseif ( 'port' === $key || 'passive' === $key || 'path_style' === $key ) {
						$storage[ $pid ][ $key ] = absint( $value );
						if ( 'passive' === $key || 'path_style' === $key ) {
							$storage[ $pid ][ $key ] = ! empty( $value );
						}
					} else {
						$storage[ $pid ][ $key ] = sanitize_text_field( (string) $value );
					}
				}
			}
		}

		// Local storage: only a safe subfolder under uploads/maca-backups (never an arbitrary absolute path).
		$subdir = isset( $storage['local']['subdir'] ) ? (string) $storage['local']['subdir'] : '';
		$subdir = sanitize_file_name( $subdir );
		$subdir = preg_replace( '/[^A-Za-z0-9_\-]/', '', $subdir ) ?? '';
		unset( $storage['local']['path'], $storage['local']['subdir'] );
		if ( '' !== $subdir ) {
			$storage['local']['path'] = trailingslashit( Maca_Backup_Pro_Paths::default_backup_dir() ) . $subdir;
		} else {
			$storage['local']['path'] = '';
		}

		Maca_Backup_Pro_Settings::update(
			array(
				'storage_provider' => $provider,
				'storage'          => $storage,
			)
		);

		add_settings_error( 'maca_backup_pro', 'storage', __( 'Storage settings saved.', 'maca-backup' ), 'success' );
	}

	/**
	 * Stream a backup download (single part or a transfer ZIP of all parts).
	 *
	 * @return void
	 */
	private function download_backup(): void {
		if ( ! Maca_Backup_Pro_Security::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'maca-backup' ) );
		}

		$id = 0;
		if ( isset( $_REQUEST['backup_id'] ) ) {
			$id = absint( wp_unslash( $_REQUEST['backup_id'] ) );
		}
		$backup = Maca_Backup_Pro_Backups_Table::get( $id );
		if ( ! $backup || 'completed' !== (string) $backup->status ) {
			wp_die( esc_html__( 'Backup not found.', 'maca-backup' ) );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) || empty( $parts ) ) {
			$message = is_wp_error( $parts )
				? $parts->get_error_message()
				: __( 'Backup archive file was not found on disk.', 'maca-backup' );
			wp_die( esc_html( $message ) );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/[^a-z0-9\-\.]+/i', '-', $host ) : 'site';
		$date = gmdate( 'Y-m-d' );
		$base = 'maca-backup-' . $host . '-' . $date . '-' . (int) $backup->id;

		if ( 1 === count( $parts ) ) {
			$local = $parts[0];
			if ( ! is_readable( $local ) ) {
				wp_die( esc_html__( 'Backup archive file was not found on disk.', 'maca-backup' ) );
			}
			$ext      = str_ends_with( strtolower( $local ), '.enc' ) ? '.zip.enc' : '.zip';
			$filename = $base . $ext;
			Maca_Backup_Pro_Download::deliver( $local, $filename, 'application/octet-stream' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive is required to download multi-part backups.', 'maca-backup' ) );
		}

		$base_dir = Maca_Backup_Pro_Settings::local_backup_dir();
		if ( '' === $base_dir ) {
			wp_die( esc_html__( 'Local backup directory is not available under uploads.', 'maca-backup' ) );
		}

		$transfer = trailingslashit( $base_dir ) . $base . '-parts.zip';
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $transfer, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not build download package.', 'maca-backup' ) );
		}
		foreach ( $parts as $part ) {
			if ( ! is_readable( $part ) ) {
				$zip->close();
				wp_delete_file( $transfer );
				wp_die( esc_html__( 'A backup part is missing on disk.', 'maca-backup' ) );
			}
			$zip->addFile( $part, basename( $part ) );
			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( basename( $part ), ZipArchive::CM_STORE );
			}
		}
		$zip->close();

		if ( ! is_readable( $transfer ) ) {
			wp_die( esc_html__( 'Could not build download package.', 'maca-backup' ) );
		}

		Maca_Backup_Pro_Download::deliver( $transfer, $base . '-parts.zip', 'application/zip', true );
	}

	/**
	 * Import a backup archive uploaded from another host / local disk.
	 *
	 * @return void
	 */
	private function import_backup(): void {
		if ( ! Maca_Backup_Pro_Security::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'maca-backup' ) );
		}

		$file = isset( $_FILES['backup_file'] ) && is_array( $_FILES['backup_file'] ) ? $_FILES['backup_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Maca_Backup_Pro_Importer::from_upload( $file );

		if ( is_wp_error( $result ) ) {
			add_settings_error( 'maca_backup_pro', 'import_fail', $result->get_error_message(), 'error' );
			return;
		}

		add_settings_error(
			'maca_backup_pro',
			'import_ok',
			sprintf(
				/* translators: %d: backup ID */
				__( 'Backup imported (#%d). You can restore it now.', 'maca-backup' ),
				(int) $result
			),
			'success'
		);

		set_transient(
			'maca_backup_pro_flash_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: backup ID */
					__( 'Backup imported (#%d). You can restore it now.', 'maca-backup' ),
					(int) $result
				),
			),
			60
		);

		wp_safe_redirect( self::tab_url( 'restore', array( 'backup_id' => (int) $result ) ) );
		exit;
	}

	/**
	 * Plugin row action links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$open    = '<a href="' . esc_url( self::tab_url( 'dashboard' ) ) . '">' . esc_html__( 'Open', 'maca-backup' ) . '</a>';
		$support = '<a href="' . esc_url( self::tab_url( 'support' ) ) . '">' . esc_html__( 'Support', 'maca-backup' ) . '</a>';
		array_unshift( $links, $open, $support );
		return $links;
	}

	/**
	 * Extra meta links under the plugin on Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public function plugin_row_meta( array $links, string $file ): array {
		if ( MACA_BACKUP_PRO_BASENAME !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( self::tab_url( 'support' ) ) . '">' . esc_html__( 'Support', 'maca-backup' ) . '</a>';
		$links[] = '<a href="' . esc_url( Maca_Backup_Pro_Legal::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'maca-backup' ) . '</a>';
		$links[] = '<a href="' . esc_url( Maca_Backup_Pro_Legal::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'maca-backup' ) . '</a>';

		return $links;
	}

	/**
	 * Single app router for all tabs.
	 *
	 * @return void
	 */
	public function render_app(): void {
		$tab = self::current_tab();
		match ( $tab ) {
			'backups'  => $this->render_backups(),
			'restore'  => $this->render_restore(),
			'smart'    => $this->render_smart(),
			'schedule' => $this->render_schedule(),
			'storage'  => $this->render_storage(),
			'logs'     => $this->render_logs(),
			'settings' => $this->render_settings(),
			'help'     => $this->render_help(),
			'support'  => $this->render_support(),
			default    => $this->render_dashboard(),
		};
	}

	/**
	 * Wrap a view.
	 *
	 * @param string               $view View file basename.
	 * @param array<string, mixed> $vars Variables.
	 * @return void
	 */
	private function render( string $view, array $vars = array() ): void {
		if ( ! Maca_Backup_Pro_Security::can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'maca-backup' ) );
		}

		// Heal missing history rows (completed job / leftover ZIPs without a backups table entry).
		Maca_Backup_Pro_Backup_Engine::reconcile_history();

		$current_tab = self::current_tab();
		$tabs        = self::tabs();

		echo '<div class="wrap maca-backup-admin">';
		include MACA_BACKUP_PRO_PATH . 'admin/partials/header.php';

		$flash = get_transient( 'maca_backup_pro_flash_' . get_current_user_id() );
		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			delete_transient( 'maca_backup_pro_flash_' . get_current_user_id() );
			$type = ( 'error' === ( $flash['type'] ?? '' ) ) ? 'error' : 'success';
			add_settings_error( 'maca_backup_pro', 'flash', (string) $flash['message'], $type );
		}

		settings_errors( 'maca_backup_pro' );

		$template = MACA_BACKUP_PRO_PATH . 'admin/views/' . $view . '.php';
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Isolated scope avoids collisions with $history etc.
		( static function ( string $template, array $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $template;
		} )( $template, $vars );

		$this->render_footer();

		echo '</div>';
	}

	/**
	 * Footer links under the admin wrap.
	 *
	 * @return void
	 */
	private function render_footer(): void {
		$sep = ' <span class="maca-bp-footer__sep" aria-hidden="true">|</span> ';
		echo '<footer class="maca-bp-footer">';
		echo '<a href="' . esc_url( self::tab_url( 'help' ) ) . '">' . esc_html__( 'Help', 'maca-backup' ) . '</a>';
		echo $sep; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static separator markup.
		echo '<a href="' . esc_url( self::tab_url( 'support' ) ) . '">' . esc_html__( 'Support', 'maca-backup' ) . '</a>';
		echo $sep; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static separator markup.
		echo '<a href="' . esc_url( self::tab_url( 'support' ) . '#maca-bp-terms' ) . '">' . esc_html__( 'Terms', 'maca-backup' ) . '</a>';
		echo $sep; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static separator markup.
		echo '<a href="' . esc_url( self::tab_url( 'support' ) . '#maca-bp-privacy' ) . '">' . esc_html__( 'Privacy', 'maca-backup' ) . '</a>';
		echo '</footer>';
	}

	/** @return void */
	public function render_dashboard(): void {
		$latest   = Maca_Backup_Pro_Backups_Table::latest_completed();
		$provider = Maca_Backup_Pro_Storage_Registry::instance()->get(
			(string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' )
		);
		$job = Maca_Backup_Pro_Jobs_Table::active_for_ui( 'backup' ) ?: Maca_Backup_Pro_Jobs_Table::active_for_ui( 'restore' );

		$show_onboarding = self::should_show_onboarding();
		$tz_name         = wp_timezone()->getName();
		$tz_label        = preg_match( '/^[+-]\d{2}:\d{2}$/', $tz_name ) ? 'UTC' . $tz_name : $tz_name;

		$vars = array(
			'latest'          => $latest,
			'count'           => Maca_Backup_Pro_Backups_Table::count_completed(),
			'total_size'      => Maca_Backup_Pro_Backups_Table::total_size(),
			'storage'         => $provider ? $provider->label() : 'Local',
			'space'           => $provider ? $provider->space_info() : null,
			'next_backup'     => Maca_Backup_Pro_Scheduler::instance()->next_run(),
			'history'         => Maca_Backup_Pro_Backups_Table::recent( 10 ),
			'active_job'      => $job,
			'show_onboarding' => $show_onboarding,
		);

		if ( $show_onboarding ) {
			$vars['current_storage']   = (string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' );
			$vars['providers']         = self::onboarding_providers();
			$vars['storage_url']       = self::tab_url( 'storage' );
			$vars['schedule_defaults'] = self::onboarding_schedule_defaults();
			$vars['tz_label']          = $tz_label;
		}

		$this->render( 'dashboard', $vars );
	}

	/** @return void */
	public function render_backups(): void {
		$this->render(
			'backups',
			array(
				'history' => Maca_Backup_Pro_Backups_Table::recent( 100 ),
			)
		);
	}

	/** @return void */
	public function render_restore(): void {
		$this->render(
			'restore',
			array(
				'history' => Maca_Backup_Pro_Backups_Table::recent_completed( 100 ),
			)
		);
	}

	/** @return void */
	public function render_smart(): void {
		$this->render(
			'smart-restore',
			array(
				'history' => Maca_Backup_Pro_Backups_Table::recent_completed( 100 ),
			)
		);
	}

	/** @return void */
	public function render_schedule(): void {
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = $edit_id ? Maca_Backup_Pro_Scheduler::get_schedule( $edit_id ) : null;
		$from_onboarding = isset( $_GET['onboarding'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['onboarding'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI flag.
		$this->render(
			'schedule',
			array(
				'schedules'       => Maca_Backup_Pro_Scheduler::all_schedules(),
				'editing'         => $editing,
				'from_onboarding' => $from_onboarding,
			)
		);
	}

	/** @return void */
	public function render_storage(): void {
		$this->render(
			'storage',
			array(
				'settings'  => Maca_Backup_Pro_Settings::all(),
				'providers' => Maca_Backup_Pro_Storage_Registry::instance()->all(),
			)
		);
	}

	/** @return void */
	public function render_logs(): void {
		$this->render( 'logs', array( 'logs' => Maca_Backup_Pro_Logs_Table::recent( 200 ) ) );
	}

	/** @return void */
	public function render_settings(): void {
		$this->render( 'settings', array( 'settings' => Maca_Backup_Pro_Settings::all() ) );
	}

	/** @return void */
	public function render_help(): void {
		$this->render( 'help' );
	}

	/** @return void */
	public function render_support(): void {
		$user = wp_get_current_user();

		$this->render(
			'support',
			array(
				'accepted'       => Maca_Backup_Pro_Legal::is_accepted(),
				'acceptance'     => Maca_Backup_Pro_Legal::get_acceptance(),
				'user_name'      => $user instanceof WP_User ? (string) $user->display_name : '',
				'user_email'     => $user instanceof WP_User && is_email( $user->user_email )
					? (string) $user->user_email
					: (string) get_option( 'admin_email' ),
				'site_url'       => untrailingslashit( home_url() ),
				'site_title'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'plugin_version' => defined( 'MACA_BACKUP_PRO_VERSION' ) ? MACA_BACKUP_PRO_VERSION : '',
			)
		);
	}
}
