<?php
/**
 * Editor-only gallery publication checklist.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\ChunkedUploads;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Shop\Delivery;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\PrintCrop;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Displays readiness using the current gallery, files and delivery settings. */
final class GalleryPreflight {

	/**
	 * Renders the checklist for a gallery the user can edit.
	 *
	 * @param WP_Post           $gallery    Gallery.
	 * @param Access            $access     Access rules.
	 * @param GalleryRepository $repository Gallery photo queries.
	 */
	public static function render( WP_Post $gallery, Access $access, GalleryRepository $repository ): void {
		if ( ! $access->is_editor( $gallery ) ) {
			return;
		}
		$images  = $repository->images( $gallery->ID );
		$pending = ChunkedUploads::pending_names( $gallery->ID );
		$rows    = [];
		foreach ( PriceLists::products( PriceLists::for_gallery( $gallery, $access ) ) as $product ) {
			if ( 'print' === $product['kind'] ) {
				$rows[] = $product;
			}
		}
		$warnings = self::warnings( $gallery, $access, $images, $rows );
		?>
		<section class="slashphoto-workspace__panel">
			<h2><?php esc_html_e( 'Publication check', 'slashphoto' ); ?></h2>
			<?php if ( $warnings ) : ?>
				<ul class="ul-disc">
					<?php foreach ( $warnings as $warning ) : ?>
						<li><?php echo esc_html( $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No issues found in the gallery and delivery settings.', 'slashphoto' ); ?></p>
			<?php endif; ?>
			<?php if ( $pending ) : ?>
				<h3><?php esc_html_e( 'Unfinished uploads', 'slashphoto' ); ?></h3>
				<ul class="ul-disc">
					<?php foreach ( $pending as $name ) : ?>
						<li><?php echo esc_html( $name ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $images ) : ?>
				<h3><?php esc_html_e( 'Original files and print sizes', 'slashphoto' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Sizes are estimates at 300 pixels per inch. A crop can reduce the usable area.', 'slashphoto' ); ?></p>
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Photo', 'slashphoto' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Original size at 300 ppi', 'slashphoto' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Print formats that fit', 'slashphoto' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $images as $image ) : ?>
							<?php
							$path = (string) wp_get_original_image_path( $image->ID );
							$size = $path && is_file( $path ) && is_readable( $path ) ? wp_getimagesize( $path ) : false;
							$fit  = [];
							if ( $size ) {
								foreach ( $rows as $product ) {
									$format = PrintCrop::format( $product['name'] );
									if ( $format && min( $size[0], $size[1] ) / 300 >= min( $format ) && max( $size[0], $size[1] ) / 300 >= max( $format ) ) {
										$fit[] = $product['name'];
									}
								}
							}
							?>
							<tr>
								<td><?php echo esc_html( get_the_title( $image ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										$size
											/* translators: 1: width in centimetres, 2: height in centimetres. */
											? sprintf( __( '%1$s × %2$s cm', 'slashphoto' ), number_format_i18n( $size[0] * 2.54 / 300, 1 ), number_format_i18n( $size[1] * 2.54 / 300, 1 ) )
											: __( 'Original missing or size unavailable', 'slashphoto' )
									);
									?>
								</td>
								<td><?php echo esc_html( $fit ? implode( ', ', $fit ) : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Finds common publication and delivery problems.
	 *
	 * @param WP_Post   $gallery Gallery.
	 * @param Access    $access  Access rules.
	 * @param WP_Post[] $images  Gallery images.
	 * @param array[]   $prints  Offered prints.
	 * @return string[]
	 */
	private static function warnings( WP_Post $gallery, Access $access, array $images, array $prints ): array {
		$warnings = [];
		$expiry   = $access->expires_at( $gallery );
		if ( $expiry && time() >= $expiry ) {
			$warnings[] = __( 'The gallery or a parent gallery has expired.', 'slashphoto' );
		}
		foreach ( $access->chain( $gallery ) as $parent ) {
			if ( $parent->ID !== $gallery->ID && 'publish' !== $parent->post_status ) {
				$warnings[] = __( 'A parent gallery needs to be published before clients can open this one.', 'slashphoto' );
				break;
			}
		}
		foreach ( $access->chain( $gallery ) as $parent ) {
			if ( Access::MODE_LINK === Access::mode( $parent ) && '' === Access::link_key( $parent ) ) {
				$warnings[] = __( 'A required secret link has not been created.', 'slashphoto' );
			}
			if ( Access::MODE_CLIENTS === Access::mode( $parent ) && ! Access::clients( $parent ) ) {
				$warnings[] = __( 'A gallery restricted to selected clients has no assigned accounts.', 'slashphoto' );
			}
		}
		if ( ! $images && ! $gallery->post_parent && ! get_children(
			[
				'post_parent' => $gallery->ID,
				'post_type'   => $gallery->post_type,
				'post_status' => 'publish',
				'numberposts' => 1,
			]
		) ) {
			$warnings[] = __( 'Add photos or publish a sub-gallery before sharing this gallery.', 'slashphoto' );
		}
		if ( $prints && ! Delivery::methods() ) {
			$warnings[] = __( 'Prints are offered, but no delivery method is enabled.', 'slashphoto' );
		}
		return array_unique( $warnings );
	}
}
