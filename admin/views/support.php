<?php
/**
 * Support + legal acceptance view.
 *
 * @package Maca_Backup_Pro
 *
 * @var bool   $accepted
 * @var array  $acceptance
 * @var string $user_name
 * @var string $user_email
 * @var string $site_url
 * @var string $site_title
 * @var string $plugin_version
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$accepted       = ! empty( $accepted );
$acceptance     = is_array( $acceptance ?? null ) ? $acceptance : array();
$user_name      = isset( $user_name ) ? (string) $user_name : '';
$user_email     = isset( $user_email ) ? (string) $user_email : '';
$site_url       = isset( $site_url ) ? (string) $site_url : untrailingslashit( home_url() );
$site_title     = isset( $site_title ) ? (string) $site_title : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
$plugin_version = isset( $plugin_version ) ? (string) $plugin_version : ( defined( 'MACA_BACKUP_PRO_VERSION' ) ? MACA_BACKUP_PRO_VERSION : '' );
$product_url    = Maca_Backup_Pro_Legal::product_url();
$terms_url      = Maca_Backup_Pro_Legal::TERMS_URL;
$privacy_url    = Maca_Backup_Pro_Legal::PRIVACY_URL;
$email          = Maca_Backup_Pro_Legal::SUPPORT_EMAIL;
$mailto         = 'mailto:' . $email . '?subject=' . rawurlencode( 'maca BackUp support' );
?>
<section class="maca-bp-panel maca-bp-support">
	<h2><?php esc_html_e( 'Support', 'maca-backup' ); ?></h2>
	<p class="maca-bp-muted">
		<?php esc_html_e( 'Send a support request to Maca Development. We reply to the email address below — you do not need to log in on maca.se.', 'maca-backup' ); ?>
	</p>

	<div class="maca-bp-support__grid">
		<div class="maca-bp-support__form-wrap">
			<?php /* AJAX-only: no action URL so a failed JS bind cannot navigate to admin-ajax.php. */ ?>
			<form id="maca-bp-support-form" class="maca-bp-support__form" method="post" action="" novalidate onsubmit="return false;">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="maca-bp-support-name"><?php esc_html_e( 'Name', 'maca-backup' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="maca-bp-support-name" name="maca_bp_support_name" value="<?php echo esc_attr( $user_name ); ?>" autocomplete="name" required />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="maca-bp-support-email"><?php esc_html_e( 'Email', 'maca-backup' ); ?></label>
						</th>
						<td>
							<input type="email" class="regular-text" id="maca-bp-support-email" name="maca_bp_support_email" value="<?php echo esc_attr( $user_email ); ?>" autocomplete="email" required />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="maca-bp-support-site"><?php esc_html_e( 'Site URL', 'maca-backup' ); ?></label>
						</th>
						<td>
							<input type="url" class="large-text" id="maca-bp-support-site" name="maca_bp_support_site" value="<?php echo esc_attr( $site_url ); ?>" readonly />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="maca-bp-support-version"><?php esc_html_e( 'Plugin version', 'maca-backup' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="maca-bp-support-version" name="maca_bp_support_version" value="<?php echo esc_attr( $plugin_version ); ?>" readonly />
							<p class="description"><?php echo esc_html( $site_title ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="maca-bp-support-subject"><?php esc_html_e( 'Subject', 'maca-backup' ); ?></label>
						</th>
						<td>
							<input type="text" class="large-text" id="maca-bp-support-subject" name="maca_bp_support_subject" required />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="maca-bp-support-body"><?php esc_html_e( 'Message', 'maca-backup' ); ?></label>
						</th>
						<td>
							<textarea class="large-text" rows="8" id="maca-bp-support-body" name="maca_bp_support_message" required></textarea>
							<p class="description">
								<?php esc_html_e( 'Describe your issue or question as clearly as you can.', 'maca-backup' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'System info', 'maca-backup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="maca-bp-support-include-info" name="maca_bp_support_include_info" value="1" checked />
								<?php esc_html_e( 'Attach site URL, plugin version, WordPress and PHP versions (recommended)', 'maca-backup' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="button" class="button button-primary" id="maca-bp-support-submit">
						<?php esc_html_e( 'Send request', 'maca-backup' ); ?>
					</button>
				</p>

				<p class="description maca-bp-support__status" id="maca-bp-support-status" aria-live="polite"></p>
			</form>
		</div>

		<aside class="maca-bp-support__aside">
			<h3><?php esc_html_e( 'More help', 'maca-backup' ); ?></h3>
			<ul>
				<li>
					<a href="<?php echo esc_url( $mailto ); ?>"><?php echo esc_html( $email ); ?></a>
				</li>
				<li>
					<a href="<?php echo esc_url( $product_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Product page (maca.se)', 'maca-backup' ); ?>
					</a>
				</li>
			</ul>

			<div class="maca-bp-support__tips">
				<h3><?php esc_html_e( 'Before you write', 'maca-backup' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Note the plugin version (prefilled above) and your WordPress / PHP versions.', 'maca-backup' ); ?></li>
					<li><?php esc_html_e( 'Describe what you tried (full / database / files backup, restore, Smart Restore, storage provider).', 'maca-backup' ); ?></li>
					<li><?php esc_html_e( 'Include any error text from the Logs tab or the progress panel.', 'maca-backup' ); ?></li>
					<li><?php esc_html_e( 'Confirm remote storage credentials only if relevant — never paste secret keys into tickets if avoidable.', 'maca-backup' ); ?></li>
				</ul>
			</div>
		</aside>
	</div>
</section>

<section class="maca-bp-panel maca-bp-legal" id="maca-bp-terms">
	<div class="maca-bp-panel__head">
		<h2><?php esc_html_e( 'Terms of Use', 'maca-backup' ); ?></h2>
		<a class="maca-bp-legal__external" href="<?php echo esc_url( $terms_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'View on maca.se', 'maca-backup' ); ?>
		</a>
	</div>
	<div class="maca-bp-legal__doc">
		<?php echo wp_kses_post( Maca_Backup_Pro_Legal::get_terms_html() ); ?>
	</div>
</section>

<section class="maca-bp-panel maca-bp-legal" id="maca-bp-privacy">
	<div class="maca-bp-panel__head">
		<h2><?php esc_html_e( 'Privacy Policy', 'maca-backup' ); ?></h2>
		<a class="maca-bp-legal__external" href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'View on maca.se', 'maca-backup' ); ?>
		</a>
	</div>
	<div class="maca-bp-legal__doc">
		<?php echo wp_kses_post( Maca_Backup_Pro_Legal::get_privacy_html() ); ?>
	</div>
</section>

<section class="maca-bp-panel maca-bp-legal-accept" id="maca-bp-accept">
	<h2><?php esc_html_e( 'Accept terms & privacy', 'maca-backup' ); ?></h2>

	<?php if ( $accepted ) : ?>
		<div class="notice notice-success inline maca-bp-legal-accept__status">
			<p>
				<?php
				$user_label = ! empty( $acceptance['user_login'] )
					? (string) $acceptance['user_login']
					: __( 'Unknown user', 'maca-backup' );
				printf(
					/* translators: 1: datetime, 2: username, 3: terms version, 4: privacy version */
					esc_html__( 'Accepted %1$s by %2$s (terms %3$s / privacy %4$s).', 'maca-backup' ),
					esc_html( (string) ( $acceptance['accepted_at'] ?? '' ) ),
					esc_html( $user_label ),
					esc_html( (string) ( $acceptance['terms_version'] ?? '' ) ),
					esc_html( (string) ( $acceptance['privacy_version'] ?? '' ) )
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-warning inline maca-bp-legal-accept__status">
			<p><?php esc_html_e( 'Backup and restore are paused until you accept both documents below.', 'maca-backup' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $accepted ) : ?>
		<form method="post" class="maca-bp-legal-accept__form">
			<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
			<input type="hidden" name="maca_backup_pro_action" value="accept_legal" />

			<label class="maca-bp-legal-accept__check">
				<input type="checkbox" name="accept_terms" value="1" required />
				<span>
					<?php
					/* translators: %s: link to terms */
					$terms_label = __( 'I have read and accept the <a href="%s">Terms of Use</a>.', 'maca-backup' );
					printf(
						wp_kses(
							$terms_label,
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						),
						esc_url( $terms_url )
					);
					?>
				</span>
			</label>

			<label class="maca-bp-legal-accept__check">
				<input type="checkbox" name="accept_privacy" value="1" required />
				<span>
					<?php
					/* translators: %s: link to privacy policy */
					$privacy_label = __( 'I have read and accept the <a href="%s">Privacy Policy</a>.', 'maca-backup' );
					printf(
						wp_kses(
							$privacy_label,
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
									'rel'    => true,
								),
							)
						),
						esc_url( $privacy_url )
					);
					?>
				</span>
			</label>

			<?php submit_button( __( 'Accept and continue', 'maca-backup' ), 'primary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
</section>
