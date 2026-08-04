<?php
/**
 * Admin header + tab navigation.
 *
 * @package Maca_Backup_Pro
 *
 * @var string $current_tab
 * @var array  $tabs
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$current_tab = isset( $current_tab ) ? (string) $current_tab : 'dashboard';
$tabs        = isset( $tabs ) && is_array( $tabs ) ? $tabs : array();
$icon_url    = MACA_BACKUP_PRO_URL . 'assets/img/icon-256x256.png';

$failed_banner = null;
if ( class_exists( 'Maca_Backup_Pro_Backups_Table', false ) ) {
	$failed_banner = Maca_Backup_Pro_Backups_Table::unresolved_failed();
	if ( $failed_banner ) {
		$dismissed_id = (int) get_user_meta( get_current_user_id(), Maca_Backup_Pro_Admin::FAILED_NOTICE_DISMISS_META, true );
		if ( $dismissed_id === (int) $failed_banner->id ) {
			$failed_banner = null;
		}
	}
}
?>
<header class="maca-bp-header">
	<div class="maca-bp-header__brand">
		<img
			class="maca-bp-header__logo"
			src="<?php echo esc_url( $icon_url ); ?>"
			alt="<?php echo esc_attr__( 'maca BackUp', 'maca-backup' ); ?>"
			width="76"
			height="76"
		/>
		<div>
			<h1 class="maca-bp-header__title"><?php esc_html_e( 'maca BackUp', 'maca-backup' ); ?></h1>
			<p class="maca-bp-header__tagline"><?php esc_html_e( 'Backup, restore & Smart Restore for WordPress.', 'maca-backup' ); ?></p>
		</div>
	</div>
	<div class="maca-bp-header__meta">
		<?php if ( $failed_banner ) : ?>
			<span class="maca-bp-badge maca-bp-badge--fail" title="<?php echo esc_attr( (string) ( $failed_banner->error_message ?? '' ) ); ?>">
				<?php esc_html_e( 'Backup failed', 'maca-backup' ); ?>
			</span>
		<?php endif; ?>
		<span class="maca-bp-badge"><?php echo esc_html( 'v' . MACA_BACKUP_PRO_VERSION ); ?></span>
	</div>
</header>

<?php if ( $failed_banner ) : ?>
	<?php
	$fail_when = Maca_Backup_Pro_Format::datetime_local(
		! empty( $failed_banner->finished_at ) ? (string) $failed_banner->finished_at : (string) $failed_banner->created_at
	);
	$fail_err  = trim( (string) ( $failed_banner->error_message ?? '' ) );
	$fail_dismiss = wp_nonce_url(
		add_query_arg( 'maca_bp_dismiss_fail', (int) $failed_banner->id ),
		'maca_bp_dismiss_fail'
	);
	?>
	<div class="maca-bp-fail-banner" role="alert">
		<div class="maca-bp-fail-banner__text">
			<strong><?php esc_html_e( 'Last backup failed', 'maca-backup' ); ?></strong>
			<span>
				<?php
				printf(
					/* translators: 1: backup type, 2: datetime */
					esc_html__( '%1$s · %2$s', 'maca-backup' ),
					esc_html( (string) $failed_banner->type ),
					esc_html( $fail_when )
				);
				?>
			</span>
			<?php if ( '' !== $fail_err ) : ?>
				<span class="maca-bp-fail-banner__error"><?php echo esc_html( $fail_err ); ?></span>
			<?php endif; ?>
		</div>
		<div class="maca-bp-fail-banner__actions">
			<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'logs' ) ); ?>"><?php esc_html_e( 'Logs', 'maca-backup' ); ?></a>
			<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'backups' ) ); ?>"><?php esc_html_e( 'Backups', 'maca-backup' ); ?></a>
			<a class="maca-bp-fail-banner__dismiss" href="<?php echo esc_url( $fail_dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'maca-backup' ); ?></a>
		</div>
	</div>
<?php endif; ?>

<?php if ( ! empty( $tabs ) ) : ?>
	<nav class="maca-bp-tabs<?php echo $failed_banner ? ' maca-bp-tabs--after-fail' : ''; ?>" aria-label="<?php esc_attr_e( 'maca BackUp sections', 'maca-backup' ); ?>">
		<ul class="maca-bp-tabs__list">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<li class="maca-bp-tabs__item">
					<a
						class="maca-bp-tabs__link<?php echo $current_tab === $slug ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( $slug ) ); ?>"
						<?php echo $current_tab === $slug ? ' aria-current="page"' : ''; ?>
					><?php echo esc_html( $label ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
<?php endif; ?>

<?php include MACA_BACKUP_PRO_PATH . 'admin/partials/progress.php'; ?>
