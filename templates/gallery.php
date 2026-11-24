<?php
/**
 * Gallery markup appended to a gallery's content.
 *
 * Override: copy this file to yourtheme/slashphoto/gallery.php.
 * Keep the slashphoto-* class names if you want the plugin's styles and
 * future scripts (lightbox, 360° viewer, proofing) to keep working.
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type WP_Post   $gallery  The gallery.
 *     @type WP_Post[] $children Sub-galleries the visitor may know about.
 *     @type int[]     $locked_children IDs of sub-galleries that need a password first (no cover shown).
 *     @type array[]   $items    Images: { attachment: WP_Post, kind: "photo"|"wide"|"360", panorama: array }.
 *     @type string    $align    "wide", "full" or "": uses the theme's own wide/full width.
 *     @type int       $proofing ID of the gallery whose client selection these images go into, 0 when proofing is off.
 *     @type array[]   $shop     Products for sale: { id, kind: "download"|"print", name, price (formatted) }; empty when not for sale.
 *     @type array     $browse   Search and pagination on single galleries.
 *     @type string    $layout   Grid layout selected in gallery settings.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_children = $args['children'] ?? [];
$slashphoto_locked   = $args['locked_children'] ?? [];
$slashphoto_items    = $args['items'] ?? [];
$slashphoto_align    = $args['align'] ?? '';
$slashphoto_proofing = (int) ( $args['proofing'] ?? 0 );
$slashphoto_shop     = $args['shop'] ?? [];
$slashphoto_browse   = $args['browse'] ?? [];
$slashphoto_classes  = 'slashphoto slashphoto-gallery' . ( $slashphoto_align ? ' align' . $slashphoto_align : '' );
?>
<div<?php echo isset( $args['gallery'] ) ? ' data-slashphoto-view="' . esc_attr( (string) $args['gallery']->ID ) . '"' : ''; ?> class="<?php echo esc_attr( $slashphoto_classes ); ?>"<?php echo $slashphoto_proofing ? ' data-slashphoto-proofing="' . esc_attr( (string) $slashphoto_proofing ) . '"' : ''; ?><?php echo $slashphoto_shop ? ' data-slashphoto-shop="' . esc_attr( (string) wp_json_encode( $slashphoto_shop ) ) . '"' : ''; ?>>
	<?php if ( $slashphoto_children ) : ?>
		<ul class="slashphoto-albums">
			<?php foreach ( $slashphoto_children as $slashphoto_child ) : ?>
				<li class="slashphoto-album">
					<a class="slashphoto-album-link" href="<?php echo esc_url( get_permalink( $slashphoto_child ) ); ?>">
						<?php
						if ( in_array( $slashphoto_child->ID, $slashphoto_locked, true ) ) {
							echo '<span class="slashphoto-album-cover slashphoto-album-cover--locked" aria-hidden="true"></span>';
						} elseif ( has_post_thumbnail( $slashphoto_child ) ) {
							echo get_the_post_thumbnail( $slashphoto_child, 'medium_large', [ 'class' => 'slashphoto-album-cover' ] );
						} else {
							echo '<span class="slashphoto-album-cover slashphoto-album-cover--empty" aria-hidden="true"></span>';
						}
						?>
						<span class="slashphoto-album-title"><?php echo esc_html( get_the_title( $slashphoto_child ) ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $slashphoto_browse && ( $slashphoto_items || '' !== $slashphoto_browse['search'] || $slashphoto_browse['previous'] ) ) : ?>
		<form class="slashphoto-browse__search" role="search" method="get" action="<?php echo esc_url( $slashphoto_browse['url'] ); ?>">
			<?php foreach ( $slashphoto_browse['params'] as $slashphoto_key => $slashphoto_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $slashphoto_key ); ?>" value="<?php echo esc_attr( $slashphoto_value ); ?>">
			<?php endforeach; ?>
			<label>
				<span><?php esc_html_e( 'Search photos', 'slashphoto' ); ?></span>
				<input type="search" name="<?php echo esc_attr( $slashphoto_browse['field'] ); ?>" value="<?php echo esc_attr( $slashphoto_browse['search'] ); ?>" maxlength="120">
			</label>
			<button type="submit" class="wp-element-button"><?php esc_html_e( 'Search', 'slashphoto' ); ?></button>
			<?php if ( '' !== $slashphoto_browse['search'] ) : ?>
				<a href="<?php echo esc_url( $slashphoto_browse['url'] ); ?>"><?php esc_html_e( 'Clear search', 'slashphoto' ); ?></a>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( $slashphoto_items ) : ?>
		<ul class="<?php echo esc_attr( 'slashphoto-grid slashphoto-grid--' . ( $args['layout'] ?? 'auto' ) ); ?>">
			<?php
			foreach ( $slashphoto_items as $slashphoto_item ) :
				$slashphoto_id   = $slashphoto_item['attachment']->ID;
				$slashphoto_kind = $slashphoto_item['kind'];
				$slashphoto_full = wp_get_attachment_image_src( $slashphoto_id, 'full' );
				?>
				<li class="slashphoto-item slashphoto-item--<?php echo esc_attr( $slashphoto_kind ); ?>" data-slashphoto-id="<?php echo esc_attr( (string) $slashphoto_id ); ?>" data-slashphoto-kind="<?php echo esc_attr( $slashphoto_kind ); ?>"
					<?php if ( '360' !== $slashphoto_kind && ! empty( $args['revisions'][ $slashphoto_id ] ) ) : ?>
						data-slashphoto-revision="<?php echo esc_url( $args['revisions'][ $slashphoto_id ] ); ?>"
					<?php endif; ?>
					<?php if ( '360' === $slashphoto_kind ) : ?>
						data-slashphoto-panorama="<?php echo esc_attr( (string) wp_json_encode( (object) $slashphoto_item['panorama'] ) ); ?>"
					<?php endif; ?>
				>
					<figure class="slashphoto-figure">
						<a class="slashphoto-link" href="<?php echo esc_url( $slashphoto_full ? $slashphoto_full[0] : '' ); ?>"
							<?php if ( $slashphoto_full ) : ?>
								data-slashphoto-width="<?php echo esc_attr( (string) $slashphoto_full[1] ); ?>" data-slashphoto-height="<?php echo esc_attr( (string) $slashphoto_full[2] ); ?>"
							<?php endif; ?>
						>
							<?php
							echo wp_get_attachment_image(
								$slashphoto_id,
								'wide' === $slashphoto_kind ? 'large' : 'medium_large',
								false,
								[
									'class'   => 'slashphoto-image',
									'loading' => 'lazy',
								]
							);
							?>
							<?php if ( '360' === $slashphoto_kind ) : ?>
								<span class="slashphoto-badge"><?php echo esc_html_x( '360°', 'panorama badge', 'slashphoto' ); ?></span>
							<?php endif; ?>
						</a>
						<?php if ( wp_get_attachment_caption( $slashphoto_id ) ) : ?>
							<figcaption class="slashphoto-caption"><?php echo esc_html( wp_get_attachment_caption( $slashphoto_id ) ); ?></figcaption>
						<?php endif; ?>
					</figure>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( $slashphoto_browse && '' !== $slashphoto_browse['search'] ) : ?>
		<p class="slashphoto-empty"><?php esc_html_e( 'No photos match your search on this page.', 'slashphoto' ); ?></p>
	<?php elseif ( ! empty( $slashphoto_browse['previous'] ) ) : ?>
		<p class="slashphoto-empty"><?php esc_html_e( 'There are no photos on this page.', 'slashphoto' ); ?></p>
	<?php elseif ( ! $slashphoto_children ) : ?>
		<p class="slashphoto-empty"><?php esc_html_e( 'This gallery has no images yet.', 'slashphoto' ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $slashphoto_browse['previous'] ) || ! empty( $slashphoto_browse['next'] ) ) : ?>
		<nav class="slashphoto-browse__pages" aria-label="<?php esc_attr_e( 'Photo pages', 'slashphoto' ); ?>">
			<?php if ( $slashphoto_browse['previous'] ) : ?>
				<a href="<?php echo esc_url( $slashphoto_browse['previous'] ); ?>" rel="prev"><?php esc_html_e( 'Previous photos', 'slashphoto' ); ?></a>
			<?php endif; ?>
			<span aria-current="page">
				<?php
				/* translators: %s: current page number */
				echo esc_html( sprintf( __( 'Page %s', 'slashphoto' ), number_format_i18n( $slashphoto_browse['page'] ) ) );
				?>
			</span>
			<?php if ( $slashphoto_browse['next'] ) : ?>
				<a href="<?php echo esc_url( $slashphoto_browse['next'] ); ?>" rel="next"><?php esc_html_e( 'Next photos', 'slashphoto' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
