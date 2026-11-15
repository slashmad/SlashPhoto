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
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_children = $args['children'] ?? [];
$slashphoto_locked   = $args['locked_children'] ?? [];
$slashphoto_items    = $args['items'] ?? [];
$slashphoto_align    = $args['align'] ?? '';
$slashphoto_classes  = 'slashphoto slashphoto-gallery' . ( $slashphoto_align ? ' align' . $slashphoto_align : '' );
?>
<div class="<?php echo esc_attr( $slashphoto_classes ); ?>">
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

	<?php if ( $slashphoto_items ) : ?>
		<ul class="slashphoto-grid">
			<?php
			foreach ( $slashphoto_items as $slashphoto_item ) :
				$slashphoto_id   = $slashphoto_item['attachment']->ID;
				$slashphoto_kind = $slashphoto_item['kind'];
				$slashphoto_full = wp_get_attachment_image_src( $slashphoto_id, 'full' );
				?>
				<li class="slashphoto-item slashphoto-item--<?php echo esc_attr( $slashphoto_kind ); ?>" data-slashphoto-id="<?php echo esc_attr( (string) $slashphoto_id ); ?>" data-slashphoto-kind="<?php echo esc_attr( $slashphoto_kind ); ?>"
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
	<?php elseif ( ! $slashphoto_children ) : ?>
		<p class="slashphoto-empty"><?php esc_html_e( 'This gallery has no images yet.', 'slashphoto' ); ?></p>
	<?php endif; ?>
</div>
