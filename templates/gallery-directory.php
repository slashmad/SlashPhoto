<?php
/**
 * Public directory and photography homepage.
 *
 * @package SlashPhoto
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_feature = $args['feature'];
$slashphoto_hero    = $args['homepage'];
$slashphoto_target  = $args['directory'] ? $args['directory'] : '#' . $args['section'];
?>
<div class="slashphoto-directory alignwide">
	<?php if ( $slashphoto_hero ) : ?>
		<section class="slashphoto-directory__hero">
			<div class="slashphoto-directory__intro">
				<h1><?php esc_html_e( 'Photo galleries', 'slashphoto' ); ?></h1>
				<p><?php esc_html_e( 'Browse the collections, choose your favourites and order prints or downloads from galleries that offer them.', 'slashphoto' ); ?></p>
				<div class="slashphoto-directory__actions">
					<a class="slashphoto-directory__primary" href="<?php echo esc_url( $slashphoto_target ); ?>"><?php esc_html_e( 'Browse galleries', 'slashphoto' ); ?></a>
					<?php if ( $args['cart'] ) : ?>
						<a class="slashphoto-directory__secondary" href="<?php echo esc_url( $args['cart'] ); ?>"><?php esc_html_e( 'View cart', 'slashphoto' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $slashphoto_feature && $slashphoto_feature['image'] ) : ?>
				<a class="slashphoto-directory__feature" href="<?php echo esc_url( $slashphoto_feature['url'] ); ?>">
					<?php
					echo wp_get_attachment_image(
						$slashphoto_feature['image'],
						'large',
						false,
						[
							'alt'           => '',
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'sizes'         => '(max-width: 720px) 100vw, 720px',
						]
					);
					?>
					<span><?php echo esc_html( $slashphoto_feature['title'] ); ?> <span aria-hidden="true">↗</span></span>
				</a>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<section id="<?php echo esc_attr( $args['section'] ); ?>" class="slashphoto-directory__collections">
		<header class="slashphoto-directory__heading">
			<div>
				<h2><?php esc_html_e( 'Public galleries', 'slashphoto' ); ?></h2>
				<p><?php esc_html_e( 'Have a private gallery? Open the link from your photographer.', 'slashphoto' ); ?></p>
			</div>
			<?php if ( $slashphoto_hero && $args['directory'] ) : ?>
				<a href="<?php echo esc_url( $args['directory'] ); ?>"><?php esc_html_e( 'All public galleries', 'slashphoto' ); ?> <span aria-hidden="true">→</span></a>
			<?php endif; ?>
		</header>

		<?php if ( $args['cards'] ) : ?>
			<ul class="slashphoto-directory__grid">
				<?php foreach ( $args['cards'] as $slashphoto_card ) : ?>
					<li class="slashphoto-directory__card">
						<a href="<?php echo esc_url( $slashphoto_card['url'] ); ?>">
							<span class="slashphoto-directory__cover">
								<?php
								if ( $slashphoto_card['image'] ) {
									echo wp_get_attachment_image(
										$slashphoto_card['image'],
										'medium_large',
										false,
										[
											'alt'   => '',
											'sizes' => '(max-width: 550px) 95vw, (max-width: 900px) 48vw, 420px',
										]
									);
								}
								?>
							</span>
							<span class="slashphoto-directory__title">
								<span><?php echo esc_html( $slashphoto_card['title'] ); ?></span>
								<span aria-hidden="true">↗</span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="slashphoto-directory__empty"><?php esc_html_e( 'No public galleries are available yet.', 'slashphoto' ); ?></p>
		<?php endif; ?>
	</section>
</div>
