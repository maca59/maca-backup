<?php
/**
 * Terms, privacy policy, and acceptance tracking.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legal documents and acceptance gate for maca BackUp.
 */
class Maca_Backup_Pro_Legal {

	public const OPTION_KEY = 'maca_backup_pro_legal_acceptance';

	/** @var string Bump when terms change materially — users must re-accept. */
	public const TERMS_VERSION = '1.1';

	/** @var string Bump when privacy policy changes materially — users must re-accept. */
	public const PRIVACY_VERSION = '1.1';

	public const PRODUCT_URL = 'https://maca.se/maca-backup/';

	/**
	 * Public support page on maca.se (secondary reference only).
	 * Primary contact UI: in-plugin Support tab form → Fluent Support API.
	 *
	 * @see self::admin_support_url()
	 * @see self::FLUENT_FORM_ID
	 * @see Maca_Backup_Pro_Support
	 */
	public const SUPPORT_URL = 'https://maca.se/support-maca-backup/';

	/**
	 * Fluent Support form / API reference ID on maca.se (REST payload form_id).
	 * In-plugin tickets POST to maca-backup/v1/support. Do not use as a primary UI CTA —
	 * admin “Support” / “contact support” links should use admin_support_url().
	 */
	public const FLUENT_FORM_ID = 4;

	public const TERMS_URL = 'https://maca.se/maca-backup/terms/';

	public const PRIVACY_URL = 'https://maca.se/maca-backup/privacy/';

	public const SUPPORT_EMAIL = 'support@maca.se';

	/**
	 * Public maca.se support page URL (secondary). For admin UI CTAs use admin_support_url().
	 *
	 * @param bool $deep_link Append ?fluent-form={FLUENT_FORM_ID} (legacy portal deep-link; prefer in-plugin form).
	 * @return string
	 */
	public static function support_url( bool $deep_link = false ): string {
		$url = (string) apply_filters( 'maca_backup_support_url', self::SUPPORT_URL );
		if ( $deep_link ) {
			$url = add_query_arg( 'fluent-form', (string) self::FLUENT_FORM_ID, $url );
		}
		return $url;
	}

	/**
	 * Product landing page URL (filterable).
	 *
	 * @return string
	 */
	public static function product_url(): string {
		return (string) apply_filters( 'maca_backup_product_url', self::PRODUCT_URL );
	}

	/**
	 * Terms page URL (filterable).
	 *
	 * @return string
	 */
	public static function terms_url(): string {
		return (string) apply_filters( 'maca_backup_terms_url', self::TERMS_URL );
	}

	/**
	 * Privacy page URL (filterable).
	 *
	 * @return string
	 */
	public static function privacy_url(): string {
		return (string) apply_filters( 'maca_backup_privacy_url', self::PRIVACY_URL );
	}

	/**
	 * Stored acceptance record.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_acceptance(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether current document versions are accepted.
	 *
	 * @return bool
	 */
	public static function is_accepted(): bool {
		$acceptance = self::get_acceptance();
		if ( empty( $acceptance['terms_version'] ) || empty( $acceptance['privacy_version'] ) ) {
			return false;
		}

		return (string) $acceptance['terms_version'] === self::TERMS_VERSION
			&& (string) $acceptance['privacy_version'] === self::PRIVACY_VERSION;
	}

	/**
	 * Persist acceptance for the current document versions.
	 *
	 * @param int $user_id Administrator who accepted.
	 * @return bool
	 */
	public static function accept( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );
		$record = array(
			'terms_version'   => self::TERMS_VERSION,
			'privacy_version' => self::PRIVACY_VERSION,
			'accepted_at'     => current_time( 'mysql' ),
			'accepted_at_gmt' => current_time( 'mysql', true ),
			'user_id'         => $user_id,
			'user_login'      => $user ? (string) $user->user_login : '',
			'plugin_version'  => defined( 'MACA_BACKUP_PRO_VERSION' ) ? MACA_BACKUP_PRO_VERSION : '',
		);

		return (bool) update_option( self::OPTION_KEY, $record, false );
	}

	/**
	 * Admin URL for the Support tab (in-plugin form + legal). Prefer this for “Support” / “contact support” CTAs.
	 *
	 * @param string $section Optional anchor: terms|privacy|accept.
	 * @return string
	 */
	public static function admin_support_url( string $section = '' ): string {
		$url = Maca_Backup_Pro_Admin::tab_url( 'support' );
		if ( in_array( $section, array( 'terms', 'privacy', 'accept' ), true ) ) {
			$url .= '#maca-bp-' . $section;
		}
		return $url;
	}

	/**
	 * Message shown when backup/restore is blocked pending acceptance.
	 *
	 * @return string
	 */
	public static function blocked_message(): string {
		return __(
			'Please accept the Terms of Use and Privacy Policy on the Support tab before starting a backup or restore.',
			'maca-backup'
		);
	}

	/**
	 * Register suggested text for the site Privacy Policy guide.
	 *
	 * @return void
	 */
	public static function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>maca BackUp creates backups of this WordPress site. Backup files are stored on your own server and/or in cloud accounts you connect yourself (for example local storage, FTP/SFTP, S3, Google Drive, Dropbox, OneDrive). maca / Maca Development does not host your backup files.</p>'
			. '<p>Hub/telemetry is off by default. If enabled under Settings → maca Hub, the plugin may send limited operational data to api.maca.se (such as the site URL, plugin version, and backup history metadata: type, date, size, status) for product statistics and hub status. Backup file contents are not sent.</p>'
			. '<p>If you use the in-plugin support form, your name, email, message, and optional site information may be sent to maca.se (Fluent Support) so we can reply.</p>'
			. '<p>See the <a href="' . esc_url( self::PRIVACY_URL ) . '">privacy policy</a> and the Support tab in wp-admin for full details.</p>';

		wp_add_privacy_policy_content(
			'maca BackUp',
			wp_kses_post( $content )
		);
	}

	/**
	 * Terms of use HTML (English).
	 *
	 * @return string
	 */
	public static function get_terms_html(): string {
		$v = esc_html( self::TERMS_VERSION );

		$html  = '<h3>Terms of Use — maca BackUp</h3>';
		$html .= '<p><em>Document version ' . $v . '</em></p>';
		$html .= '<p>These Terms of Use govern your use of the WordPress plugin <strong>maca BackUp</strong>, provided by Maca Development (<a href="https://maca.se/">maca.se</a>). By accepting these terms in wp-admin, you agree to them on behalf of the site where the plugin is installed.</p>';
		$html .= '<h4>1. License</h4>';
		$html .= '<p>maca BackUp is distributed under the GNU General Public License (GPL), version 2 or later, unless a different license is stated in the plugin headers. You may use, modify, and redistribute the software in accordance with the GPL. Nothing in these terms limits your rights under the GPL.</p>';
		$html .= '<h4>2. Where backups are stored</h4>';
		$html .= '<p>Backup archives remain on <strong>your</strong> WordPress server and/or in <strong>your own</strong> remote storage accounts (local disk, FTP/SFTP, S3-compatible storage, Google Drive, Dropbox, OneDrive, or other destinations you configure). maca does <strong>not</strong> host, store, receive, or mirror your backup files on maca servers.</p>';
		$html .= '<h4>3. Your responsibilities</h4><ul>';
		$html .= '<li>You are responsible for configuring retention, storage destinations, schedules, and access credentials securely.</li>';
		$html .= '<li>You should regularly verify that backups complete successfully and <strong>test restores</strong> in a safe environment before relying on them in an emergency.</li>';
		$html .= '<li>You remain responsible for compliance with applicable law regarding data you back up, including personal data of your site visitors and users.</li>';
		$html .= '<li>You are responsible for safeguarding WordPress admin access, hosting, and any API keys or passwords you enter for remote storage.</li>';
		$html .= '</ul>';
		$html .= '<h4>4. Optional telemetry and hub</h4>';
		$html .= '<p>Hub/telemetry is <strong>off by default</strong>. If you enable it under Settings → maca Hub, the plugin may send limited operational information—such as the site URL, plugin version, and backup history metadata (type, date/time, size, status)—to <code>api.maca.se</code> for product improvement and hub heartbeat status. Backup file contents are not included. You may turn this off again at any time.</p>';
		$html .= '<h4>5. Support requests</h4>';
		$html .= '<p>Use the support form on the plugin Support tab in wp-admin. Information you provide (name, email, message, subject, and optional site / system details) may be sent to maca.se and processed via Fluent Support so we can assist you. Email to <a href="mailto:support@maca.se">support@maca.se</a> is a secondary contact option.</p>';
		$html .= '<h4>6. No warranty</h4>';
		$html .= '<p>The software is provided “as is”, without warranty of any kind, express or implied, including but not limited to merchantability, fitness for a particular purpose, and non-infringement. To the maximum extent permitted by law, Maca Development is not liable for data loss, failed restores, downtime, security incidents, or any damages arising from use of the plugin.</p>';
		$html .= '<h4>7. Changes</h4>';
		$html .= '<p>We may update these terms from time to time. Material changes are reflected in a new document version and require re-acceptance in wp-admin before backup or restore features continue.</p>';
		$html .= '<h4>8. Contact</h4>';
		$html .= '<p>Product: <a href="https://maca.se/maca-backup/">maca.se/maca-backup</a>. Support: the form on this Support tab, or <a href="mailto:support@maca.se">support@maca.se</a>.</p>';

		return $html;
	}

	/**
	 * Privacy policy HTML (English).
	 *
	 * @return string
	 */
	public static function get_privacy_html(): string {
		$v = esc_html( self::PRIVACY_VERSION );

		$html  = '<h3>Privacy Policy — maca BackUp</h3>';
		$html .= '<p><em>Document version ' . $v . '</em></p>';
		$html .= '<p>This Privacy Policy describes how <strong>maca BackUp</strong> handles data when the plugin is used on your WordPress site. The product name is maca BackUp (not “Pro”). Provider: Maca Development (<a href="https://maca.se/">maca.se</a>).</p>';
		$html .= '<h4>1. Backup data</h4>';
		$html .= '<p>Backups may include files and database content from your site (themes, plugins, uploads, posts, users, settings, and similar). That data stays under <strong>your control</strong>: on your server and/or in cloud accounts you configure. maca does not receive or host backup archives.</p>';
		$html .= '<h4>2. Credentials you enter</h4>';
		$html .= '<p>Storage credentials (FTP passwords, API tokens, S3 keys, OAuth tokens, and similar) are stored in your WordPress database or options as configured by the plugin. They are used only to transfer backups to destinations you choose. Keep your WordPress admin and hosting secure. maca does not collect these credentials for hosting backups.</p>';
		$html .= '<h4>3. Optional telemetry / hub</h4>';
		$html .= '<p>Hub/telemetry is <strong>off by default</strong>. If you enable it under Settings → maca Hub, limited metadata may be sent to <code>api.maca.se</code>—for example the site URL, plugin version, and backup history entries (type, date/time, size, status). Backup file contents and database dumps are not uploaded to maca. You can turn this off again at any time in Settings.</p>';
		$html .= '<h4>4. Support form and contact</h4>';
		$html .= '<p>If you use the in-plugin support form on the Support tab, the plugin may send your name, email address, subject, message, site URL, plugin version, and optional system information to maca.se, where tickets are handled via Fluent Support (or equivalent). If you email <a href="mailto:support@maca.se">support@maca.se</a>, we process the information you provide (and any diagnostics you choose to share) to respond to your request.</p>';
		$html .= '<h4>5. Your obligations</h4>';
		$html .= '<p>You are the controller of personal data contained in your site backups. You are responsible for retention settings, restore testing, destination security, and ensuring you have a lawful basis to process and store that data.</p>';
		$html .= '<h4>6. Third parties</h4>';
		$html .= '<p>Remote storage providers you connect (for example Google, Dropbox, Microsoft, FTP hosts, or S3-compatible services) process data under their own terms. maca is not responsible for those services. Support tooling on maca.se (including Fluent) processes support submissions as described above.</p>';
		$html .= '<h4>7. Contact</h4>';
		$html .= '<p>Product page: <a href="https://maca.se/maca-backup/">maca.se/maca-backup</a>. Support: the form on this Support tab, or <a href="mailto:support@maca.se">support@maca.se</a>.</p>';

		return $html;
	}
}
