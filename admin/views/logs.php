<?php
/**
 * Logs view.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $logs
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Logs', 'maca-backup' ); ?></h2>
	<?php if ( empty( $logs ) ) : ?>
		<p class="maca-bp-muted"><?php esc_html_e( 'No log entries yet.', 'maca-backup' ); ?></p>
	<?php else : ?>
		<table class="widefat striped maca-bp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Level', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Message', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Backup', 'maca-backup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( Maca_Backup_Pro_Format::datetime_local( (string) $log->created_at ) ); ?></td>
						<td><span class="maca-bp-pill maca-bp-pill--<?php echo esc_attr( (string) $log->level ); ?>"><?php echo esc_html( (string) $log->level ); ?></span></td>
						<td><?php echo esc_html( (string) $log->message ); ?></td>
						<td><?php echo $log->backup_id ? esc_html( '#' . $log->backup_id ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
