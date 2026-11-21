<?php
/**
 * One API credential field on Library - Settings.
 *
 * Expects $clib_secret = [ 'field' => settings key, 'label' => text, 'clear' => clear box name or '' ].
 * The saved value is never printed: only its length and last four characters.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_sf_field = sanitize_key( $clib_secret['field'] );
$clib_sf_id    = 'clib-secret-' . $clib_sf_field;
$clib_sf_hint  = CLIB_Settings::secret_hint( $clib_sf_field );
?>
<span class="clib-secret-row">
	<label for="<?php echo esc_attr( $clib_sf_id ); ?>"><?php echo esc_html( $clib_secret['label'] ); ?></label><br>
	<?php // new-password stops browsers from filling in a saved login password; the server refuses anything that is not a whole, valid key. ?>
	<input type="password" id="<?php echo esc_attr( $clib_sf_id ); ?>" name="clib[<?php echo esc_attr( $clib_sf_field ); ?>]" value="" maxlength="256" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" class="clib-secret" placeholder="<?php echo '' !== $clib_sf_hint ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>">
	<button type="button" class="button clib-reveal" aria-controls="<?php echo esc_attr( $clib_sf_id ); ?>" aria-pressed="false"><?php esc_html_e( 'Show', 'church-library' ); ?></button>
	<?php if ( '' !== $clib_sf_hint ) : ?>
		<span class="description clib-secret-hint"><?php echo esc_html( $clib_sf_hint ); ?></span>
		<?php if ( '' !== $clib_secret['clear'] ) : ?>
			<label><input type="checkbox" name="clib[<?php echo esc_attr( sanitize_key( $clib_secret['clear'] ) ); ?>]" value="1"> <?php esc_html_e( 'Remove saved value', 'church-library' ); ?></label>
		<?php endif; ?>
	<?php endif; ?>
</span>
