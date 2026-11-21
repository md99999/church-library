<?php
/**
 * Library - Settings > Installation health. Read-only; every value is escaped here.
 *
 * Local checks run on page load. Web checks (requests to this site and to
 * api.wordpress.org) run only when the button is pressed, and their result is
 * kept for this user for 10 minutes.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_h_sections = CLIB_Health::local_checks();
$clib_h_web      = get_transient( CLIB_Health::NET_PREFIX . get_current_user_id() );
if ( is_array( $clib_h_web ) && isset( $clib_h_web['rows'], $clib_h_web['time'] ) && is_array( $clib_h_web['rows'] ) ) {
	$clib_h_sections[ __( 'Web checks', 'church-library' ) ] = $clib_h_web['rows'];
} else {
	$clib_h_web = null;
}
$clib_h_count  = CLIB_Health::summary( $clib_h_sections );
$clib_h_labels = array(
	'ok'      => __( 'OK', 'church-library' ),
	'info'    => __( 'Note', 'church-library' ),
	'warning' => __( 'Warning', 'church-library' ),
	'error'   => __( 'Problem', 'church-library' ),
);
$clib_h_class = $clib_h_count['error'] ? 'clib-health-bad' : ( $clib_h_count['warning'] ? 'clib-health-warn' : 'clib-health-good' );
?>
<div id="clib-health" class="clib-health <?php echo esc_attr( $clib_h_class ); ?>">
	<h2><?php esc_html_e( 'Installation health', 'church-library' ); ?></h2>
	<p class="description">
		<?php
		/* translators: 1: plugin version, 2: folder name */
		echo esc_html( sprintf( __( 'Church Library %1$s, installed in wp-content/plugins/%2$s. These checks only read; nothing is changed.', 'church-library' ), CLIB_VERSION, CLIB_Health::folder() ) );
		?>
	</p>
	<p class="clib-health-summary">
		<?php
		if ( $clib_h_count['error'] || $clib_h_count['warning'] ) {
			/* translators: 1: number of problems, 2: number of warnings */
			echo esc_html( sprintf( __( '%1$d problem(s) and %2$d warning(s) found. Details below.', 'church-library' ), $clib_h_count['error'], $clib_h_count['warning'] ) );
		} else {
			esc_html_e( 'Nothing to fix.', 'church-library' );
		}
		?>
	</p>

	<?php foreach ( $clib_h_sections as $clib_h_title => $clib_h_rows ) : ?>
		<h3><?php echo esc_html( $clib_h_title ); ?></h3>
		<table class="widefat striped clib-health-table">
			<tbody>
			<?php foreach ( (array) $clib_h_rows as $clib_h_row ) : ?>
				<?php
				if ( ! is_array( $clib_h_row ) || ! isset( $clib_h_row['label'], $clib_h_row['status'], $clib_h_row['detail'] ) ) {
					continue;
				}
				$clib_h_status = isset( $clib_h_labels[ $clib_h_row['status'] ] ) ? $clib_h_row['status'] : 'info';
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $clib_h_row['label'] ); ?></th>
					<td class="clib-health-col-status"><span class="clib-health-pill clib-health-<?php echo esc_attr( $clib_h_status ); ?>"><?php echo esc_html( $clib_h_labels[ $clib_h_status ] ); ?></span></td>
					<td>
						<?php echo esc_html( $clib_h_row['detail'] ); ?>
						<?php if ( ! empty( $clib_h_row['items'] ) && is_array( $clib_h_row['items'] ) ) : ?>
							<ul class="clib-health-items">
								<?php foreach ( $clib_h_row['items'] as $clib_h_item ) : ?>
									<li><code><?php echo esc_html( (string) $clib_h_item ); ?></code></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-health-form">
		<?php wp_nonce_field( 'clib_health_check' ); ?>
		<input type="hidden" name="action" value="clib_health_check">
		<?php submit_button( __( 'Run web checks', 'church-library' ), 'secondary', 'submit', false ); ?>
		<span class="description">
			<?php
			if ( $clib_h_web ) {
				/* translators: %s: time */
				echo esc_html( sprintf( __( 'Last run at %s.', 'church-library' ), wp_date( get_option( 'time_format' ), (int) $clib_h_web['time'] ) ) ) . ' ';
			}
			esc_html_e( 'Rescans the plugin folder, asks this site whether it serves files that should be blocked (README.md, and .git when present), and tests an outgoing https request to api.wordpress.org. Nothing else is sent.', 'church-library' );
			?>
		</span>
	</form>
	<p class="description"><?php esc_html_e( 'How to build an installable zip from the GitHub repository is in README.md under "Building the install zip". Found a security problem? Please report it privately to sysop@maddogproductions.online, not in a public issue.', 'church-library' ); ?></p>
</div>
