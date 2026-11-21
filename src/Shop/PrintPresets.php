<?php
/**
 * Editable starting points for print options.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

defined( 'ABSPATH' ) || exit;

/** Catalogue examples used only by the price-list editor. */
final class PrintPresets {

	public const IDS = [ 'crimson', 'whitewall', 'theprintspace' ];

	/**
	 * Keeps the known template IDs in catalogue order.
	 *
	 * @param mixed $value Saved or submitted template IDs.
	 * @return string[]
	 */
	public static function sanitize( $value ): array {
		return is_array( $value ) ? array_values( array_intersect( self::IDS, array_filter( $value, 'is_string' ) ) ) : [];
	}

	/**
	 * Returns template names, reference pages and unpriced paper/frame pairs.
	 *
	 * @return array<string, array{name: string, url: string, options: array}>
	 */
	public static function all(): array {
		$matte = __( 'Matte photo paper', 'slashphoto' );
		$black = __( 'Black wood frame', 'slashphoto' );
		$oak   = __( 'Oak frame', 'slashphoto' );
		$art   = __( 'Giclée fine art paper', 'slashphoto' );
		$fuji  = __( 'Fuji Crystal DP II (matte)', 'slashphoto' );

		return [
			'crimson'       => [
				'name'    => 'Crimson',
				'url'     => 'https://crimson.se/bestall-bilder',
				'options' => [
					[ $matte, '' ],
					[ __( 'Glossy photo paper', 'slashphoto' ), '' ],
					[ $matte, $black ],
					[ __( 'Fine art paper', 'slashphoto' ), $oak ],
				],
			],
			'whitewall'     => [
				'name'    => 'WhiteWall',
				'url'     => 'https://www.whitewall.com/us/photo-prints',
				'options' => [
					[ $fuji, '' ],
					[ __( 'Fuji Crystal DP II (glossy)', 'slashphoto' ), '' ],
					[ __( 'Fuji Crystal DP II (silk)', 'slashphoto' ), '' ],
					[ $fuji, $black ],
				],
			],
			'theprintspace' => [
				'name'    => 'theprintspace',
				'url'     => 'https://www.theprintspace.co.uk/help/knowledge-base/how-to-set-up-and-sell-art-prints-with-frames/',
				'options' => [
					[ $art, '' ],
					[ $art, $black ],
					[ $art, __( 'White wood frame', 'slashphoto' ) ],
					[ $art, $oak ],
				],
			],
		];
	}
}
