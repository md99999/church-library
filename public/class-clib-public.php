<?php
/**
 * Front-end catalog: [church_library] shortcode on the "Library - Catalog" page.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Public {

	public static function init() {
		add_shortcode( 'church_library', array( __CLASS__, 'shortcode' ) );
	}

	/** Create the "Library - Catalog" page once (never overwritten). */
	public static function ensure_page() {
		$id = (int) CLIB_Settings::get( 'catalog_page_id' );
		if ( $id && get_post( $id ) ) {
			return;
		}
		$id = wp_insert_post(
			array(
				'post_title'   => 'Library - Catalog',
				'post_content' => '<!-- wp:shortcode -->[church_library]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			CLIB_Settings::set( 'catalog_page_id', (int) $id );
		}
	}

	public static function shortcode() {
		wp_enqueue_style( 'clib-public', CLIB_URL . 'assets/public.css', array(), CLIB_VERSION );

		if ( ! CLIB_Perms::can_view() ) {
			if ( ! is_user_logged_in() ) {
				return '<div class="clib clib-locked"><p>' . esc_html__( 'Please log in to view the library catalog.', 'church-library' ) . '</p><p><a class="clib-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'church-library' ) . '</a></p></div>';
			}
			return '<div class="clib clib-locked"><p>' . esc_html__( 'Your account does not have access to the library catalog.', 'church-library' ) . '</p></div>';
		}

		$code = CLIB_Util::token( CLIB_Util::get( 'clib_item' ), 32 );
		ob_start();
		if ( '' !== $code ) {
			self::render_item( $code );
		} else {
			self::render_list();
		}
		return ob_get_clean();
	}

	private static function base_url() {
		return get_permalink();
	}

	private static function render_list() {
		$args = array(
			'q'             => CLIB_Util::text( CLIB_Util::get( 'clib_q' ), 100 ),
			'media_type_id' => CLIB_Util::int( CLIB_Util::get( 'clib_type' ) ),
			'church_id'     => CLIB_Util::int( CLIB_Util::get( 'clib_church' ) ),
			'status'        => CLIB_Util::enum( CLIB_Util::get( 'clib_status' ), CLIB_Util::STATUSES, '' ),
			'page'          => CLIB_Util::int( CLIB_Util::get( 'clib_page', 1 ), 1, 100000 ),
			'per_page'      => (int) CLIB_Settings::get( 'per_page' ),
			'orderby'       => 'title',
		);
		list( $rows, $total ) = CLIB_Items::search( $args );
		$pages                = max( 1, (int) ceil( $total / $args['per_page'] ) );
		$statuses             = CLIB_Util::status_labels();
		?>
		<div class="clib clib-catalog">
			<form class="clib-search" method="get" action="<?php echo esc_url( self::base_url() ); ?>">
				<?php
				// Keep page_id when permalinks are plain.
				$page_id = CLIB_Util::int( CLIB_Util::get( 'page_id' ) );
				if ( $page_id ) {
					echo '<input type="hidden" name="page_id" value="' . esc_attr( $page_id ) . '">';
				}
				?>
				<input type="search" name="clib_q" maxlength="100" value="<?php echo esc_attr( $args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Search title, author, ISBN...', 'church-library' ); ?>">
				<select name="clib_type">
					<option value="0"><?php esc_html_e( 'All media types', 'church-library' ); ?></option>
					<?php foreach ( CLIB_LOV::all( 'media_type', true ) as $id => $row ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $args['media_type_id'], $id ); ?>><?php echo esc_html( $row->label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="clib_church">
					<option value="0"><?php esc_html_e( 'All churches', 'church-library' ); ?></option>
					<?php foreach ( CLIB_LOV::all( 'church', true ) as $id => $row ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $args['church_id'], $id ); ?>><?php echo esc_html( $row->label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="clib_status">
					<option value=""><?php esc_html_e( 'Any status', 'church-library' ); ?></option>
					<?php foreach ( $statuses as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="clib-btn"><?php esc_html_e( 'Search', 'church-library' ); ?></button>
			</form>

			<p class="clib-count">
				<?php
				/* translators: %s: number of items */
				echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'church-library' ), number_format_i18n( $total ) ) );
				?>
			</p>

			<?php if ( $rows ) : ?>
				<ul class="clib-grid">
					<?php foreach ( $rows as $r ) : ?>
						<li class="clib-card">
							<a href="<?php echo esc_url( add_query_arg( 'clib_item', $r->item_code, self::base_url() ) ); ?>">
								<span class="clib-thumb">
									<?php
									if ( $r->image_id ) {
										echo wp_get_attachment_image( (int) $r->image_id, 'thumbnail', false, array( 'alt' => '' ) );
									}
									?>
								</span>
								<span class="clib-card-body">
									<strong><?php echo esc_html( $r->title ); ?></strong>
									<?php if ( $r->creator ) : ?>
										<span class="clib-creator"><?php echo esc_html( $r->creator ); ?></span>
									<?php endif; ?>
									<span class="clib-meta"><?php echo esc_html( $r->media_type_label ); ?><?php echo $r->church_label ? ' &middot; ' . esc_html( $r->church_label ) : ''; ?></span>
									<span class="clib-status clib-status-<?php echo esc_attr( $r->storage_status ); ?>"><?php echo esc_html( CLIB_Util::label( $statuses, $r->storage_status ) ); ?></span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No items found.', 'church-library' ); ?></p>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
				<nav class="clib-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'     => add_query_arg( 'clib_page', '%#%' ), // paginate_links() runs esc_url() on output.
								'format'   => '',
								'current'  => $args['page'],
								'total'    => $pages,
								'add_args' => false,
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_item( $code ) {
		$item = CLIB_Items::find_by_code( $code );
		if ( ! $item || $item->item_code !== $code ) {
			echo '<div class="clib"><p>' . esc_html__( 'Item not found.', 'church-library' ) . '</p><p><a href="' . esc_url( self::base_url() ) . '">' . esc_html__( 'Back to catalog', 'church-library' ) . '</a></p></div>';
			return;
		}
		$statuses = CLIB_Util::status_labels();
		$avail    = max( 0, (int) $item->copies - CLIB_Loans::active_count( $item->id ) );
		$fields   = array(
			__( 'Item ID', 'church-library' )         => $item->item_code,
			__( 'Creator/Author', 'church-library' )  => $item->creator,
			__( 'Publisher', 'church-library' )       => $item->publisher,
			__( 'Media Type', 'church-library' )      => CLIB_LOV::label( $item->media_type_id ),
			__( 'Sub-category', 'church-library' )    => CLIB_LOV::label( $item->subcategory_id ),
			__( 'Difficulty', 'church-library' )      => CLIB_Util::label( CLIB_Util::difficulty_labels(), $item->difficulty ),
			__( 'Classification', 'church-library' )  => CLIB_LOV::label( $item->classification_id ),
			__( 'Liturgical Use', 'church-library' )  => $item->liturgical_use,
			__( 'ISBN / Identifier', 'church-library' ) => $item->identifier,
			__( 'Call #', 'church-library' )          => $item->call_number,
			__( 'Condition', 'church-library' )       => CLIB_Util::label( CLIB_Util::condition_labels(), $item->item_condition ),
			__( 'Church', 'church-library' )          => CLIB_LOV::label( $item->church_id ),
			__( 'Building', 'church-library' )        => CLIB_LOV::label( $item->building_id ),
			__( 'Room', 'church-library' )            => $item->room,
			__( 'Location ID', 'church-library' )     => $item->location_code,
			__( 'Owned By', 'church-library' )        => $item->owned_by,
			/* translators: 1: available copies, 2: total copies */
			__( 'Copies', 'church-library' )          => sprintf( __( '%1$d of %2$d available', 'church-library' ), $avail, (int) $item->copies ),
		);
		if ( CLIB_Perms::can_see_borrowers() ) {
			$names = wp_list_pluck( CLIB_Loans::active_for_item( $item->id ), 'borrower_name' );
			if ( $names ) {
				$fields[ __( 'Checked Out By', 'church-library' ) ] = implode( ', ', $names );
			}
		}
		?>
		<div class="clib clib-item">
			<p><a href="<?php echo esc_url( self::base_url() ); ?>">&larr; <?php esc_html_e( 'Back to catalog', 'church-library' ); ?></a></p>
			<div class="clib-item-head">
				<?php if ( $item->image_id ) : ?>
					<div class="clib-item-image"><?php echo wp_get_attachment_image( (int) $item->image_id, 'medium', false, array( 'alt' => $item->title ) ); ?></div>
				<?php endif; ?>
				<div>
					<h2><?php echo esc_html( $item->title ); ?></h2>
					<span class="clib-status clib-status-<?php echo esc_attr( $item->storage_status ); ?>"><?php echo esc_html( CLIB_Util::label( $statuses, $item->storage_status ) ); ?></span>
					<?php if ( $item->description ) : ?>
						<div class="clib-desc"><?php echo wp_kses_post( wpautop( esc_html( $item->description ) ) ); ?></div>
					<?php endif; ?>
				</div>
			</div>
			<table class="clib-fields">
				<?php foreach ( $fields as $label => $value ) : ?>
					<?php
					if ( '' === (string) $value ) {
						continue;
					}
					?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}
}
