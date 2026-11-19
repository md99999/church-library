<?php
/**
 * Library - Catalog: bulk edit confirmation.
 *
 * @package ChurchLibrary
 * @var array  $clib_req
 * @var string $clib_bulk_token
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_n       = count( $clib_req['ids'] );
$clib_changes = CLIB_Bulk::describe( $clib_req );
$clib_sample  = CLIB_Bulk::sample( $clib_req );
$clib_token   = preg_replace( '/[^A-Za-z0-9]/', '', (string) $clib_bulk_token );
?>
<h1><?php esc_html_e( 'Library - Bulk Edit', 'church-library' ); ?></h1>

<div class="clib-danger clib-bulk-confirm">
	<p><strong>
		<?php
		/* translators: %s: number of items */
		echo esc_html( sprintf( _n( 'You are about to change %s item.', 'You are about to change %s items.', $clib_n, 'church-library' ), number_format_i18n( $clib_n ) ) );
		?>
	</strong></p>
	<ul class="ul-disc">
		<?php foreach ( $clib_changes as $clib_c ) : ?>
			<li><?php echo esc_html( $clib_c ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p><?php esc_html_e( 'This cannot be undone automatically. The change is recorded in the audit log.', 'church-library' ); ?></p>
</div>

<h2><?php echo esc_html( $clib_n > count( $clib_sample ) ? __( 'First items affected', 'church-library' ) : __( 'Items affected', 'church-library' ) ); ?></h2>
<ul>
	<?php foreach ( $clib_sample as $clib_s ) : ?>
		<li><code><?php echo esc_html( $clib_s->item_code ); ?></code> <?php echo esc_html( $clib_s->title ); ?></li>
	<?php endforeach; ?>
	<?php if ( $clib_n > count( $clib_sample ) ) : ?>
		<li>
			<?php
			/* translators: %s: number of further items */
			echo esc_html( sprintf( __( '…and %s more', 'church-library' ), number_format_i18n( $clib_n - count( $clib_sample ) ) ) );
			?>
		</li>
	<?php endif; ?>
</ul>

<div class="clib-export">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'clib_bulk_apply' ); ?>
		<input type="hidden" name="action" value="clib_bulk_apply">
		<input type="hidden" name="token" value="<?php echo esc_attr( $clib_token ); ?>">
		<button type="submit" class="button button-primary">
			<?php
			/* translators: %s: number of items */
			echo esc_html( sprintf( _n( 'Apply to %s item', 'Apply to %s items', $clib_n, 'church-library' ), number_format_i18n( $clib_n ) ) );
			?>
		</button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'clib_bulk_cancel' ); ?>
		<input type="hidden" name="action" value="clib_bulk_cancel">
		<input type="hidden" name="token" value="<?php echo esc_attr( $clib_token ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'church-library' ); ?></button>
	</form>
</div>
