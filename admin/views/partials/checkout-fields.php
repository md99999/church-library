<?php
/**
 * Shared borrower fields for check-out forms.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_due_default = ( new DateTime( 'now', wp_timezone() ) )->modify( '+' . (int) CLIB_Settings::get( 'loan_days' ) . ' days' )->format( 'Y-m-d' );
?>
<p>
	<label><?php esc_html_e( 'Borrower name', 'church-library' ); ?><br>
		<input type="text" name="borrower_name" maxlength="150" class="regular-text"></label>
</p>
<p>
	<label><?php esc_html_e( 'Site account (username or email, optional)', 'church-library' ); ?><br>
		<input type="text" name="borrower_account" maxlength="100" class="regular-text" autocomplete="off"></label>
	<br><span class="description"><?php esc_html_e( 'Enter a name, an account, or both. Linking an account lets the borrower see the loan on Library - My Loans.', 'church-library' ); ?></span>
</p>
<p>
	<label><?php esc_html_e( 'Contact (phone or email)', 'church-library' ); ?><br>
		<input type="text" name="borrower_contact" maxlength="150" class="regular-text"></label>
</p>
<p>
	<label><?php esc_html_e( 'Due date', 'church-library' ); ?><br>
		<input type="date" name="due_date" value="<?php echo esc_attr( $clib_due_default ); ?>"></label>
</p>
<p>
	<label><?php esc_html_e( 'Notes', 'church-library' ); ?><br>
		<input type="text" name="out_notes" maxlength="500" class="regular-text"></label>
</p>
