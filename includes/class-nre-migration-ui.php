<?php
/**
 * NRE_Migration_UI — Admin UI for migration context: badges, filter bar, scripts.
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for migration context: badges, filter bar, scripts.
 */
class NRE_Migration_UI {

	/**
	 * Aggregate list of unique migrations for the current post, keyed by "name|timestamp".
	 *
	 * @var array<string, array{name: string, timestamp: int, date: string, revisionIds: int[]}>
	 */
	private static $migrations = [];

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'wp_prepare_revision_for_js', [ $this, 'add_migration_data_to_revision' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_revision_assets' ] );
		add_action( 'admin_footer', [ $this, 'print_migration_templates' ] );
		add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
	}

	/**
	 * Attach migration data to each revision's JS payload.
	 *
	 * @param array   $data     Revision data array for JS.
	 * @param WP_Post $revision The revision post object.
	 * @param WP_Post $post     The parent post object.
	 * @return array Modified revision data.
	 */
	public function add_migration_data_to_revision( $data, $revision, $post ) {
		$name = get_post_meta( $revision->ID, '_nre_migration_name', true );
		$ts   = get_post_meta( $revision->ID, '_nre_migration_ts', true );

		if ( $name && $ts ) {
			$ts   = (int) $ts;
			$date = wp_date( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ), $ts );

			$data['nreMigration'] = [
				'name'      => $name,
				'timestamp' => $ts,
				'date'      => $date,
			];

			// Build aggregate list of unique migrations for this post.
			$key = $name . '|' . $ts;
			if ( ! isset( self::$migrations[ $key ] ) ) {
				self::$migrations[ $key ] = [
					'name'        => $name,
					'timestamp'   => $ts,
					'date'        => $date,
					'revisionIds' => [],
				];
			}
			self::$migrations[ $key ]['revisionIds'][] = (int) $data['id'];
		} else {
			$data['nreMigration'] = false;
		}

		return $data;
	}

	/**
	 * Enqueue the revision assets on the revision.php screen only.
	 */
	public function enqueue_revision_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'revision' !== $screen->id ) {
			return;
		}

		wp_enqueue_script(
			'nre-revisions',
			plugins_url( 'assets/js/nre-revisions.js', __DIR__ ),
			[ 'revisions' ],
			NRE_VERSION,
			true
		);

		wp_enqueue_style(
			'nre-revisions',
			plugins_url( 'assets/css/nre-revisions.css', __DIR__ ),
			[ 'revisions' ],
			NRE_VERSION
		);
	}

	/**
	 * Print the custom Underscore templates and localized migration settings.
	 *
	 * Only outputs on the revision screen.
	 */
	public function print_migration_templates() {
		$screen = get_current_screen();
		if ( ! $screen || 'revision' !== $screen->id ) {
			return;
		}

		global $post;

		$this->print_template( $post );
		$this->print_settings();
	}

	/**
	 * Print the custom revision meta Underscore template with migration badge.
	 *
	 * This is a copy of WP's tmpl-revisions-meta with a migration badge injected
	 * after the date line.
	 *
	 * @param WP_Post $post The parent post object.
	 */
	private function print_template( $post ) {
		$post_locked = wp_check_post_lock( $post->ID );
		?>
		<script id="tmpl-nre-revisions-meta" type="text/html">
			<# if ( ! _.isUndefined( data.attributes ) ) { #>
				<div class="diff-title">
					<# if ( 'from' === data.type ) { #>
						<strong id="diff-title-from"><?php echo esc_html_x( 'From:', 'Followed by post revision info', 'newspack-revisions-enhanced' ); ?></strong>
					<# } else if ( 'to' === data.type ) { #>
						<strong id="diff-title-to"><?php echo esc_html_x( 'To:', 'Followed by post revision info', 'newspack-revisions-enhanced' ); ?></strong>
					<# } #>
					<div class="author-card<# if ( data.attributes.autosave ) { #> autosave<# } #>">
						<div>
							{{{ data.attributes.author.avatar }}}
							<div class="author-info" id="diff-title-author">
							<# if ( data.attributes.autosave ) { #>
								<span class="byline">
								<?php
								printf(
									/* translators: %s: User's display name. */
									esc_html__( 'Autosave by %s', 'newspack-revisions-enhanced' ),
									'<span class="author-name">{{ data.attributes.author.name }}</span>'
								);
								?>
									</span>
							<# } else if ( data.attributes.current ) { #>
								<span class="byline">
								<?php
								printf(
									/* translators: %s: User's display name. */
									esc_html__( 'Current Revision by %s', 'newspack-revisions-enhanced' ),
									'<span class="author-name">{{ data.attributes.author.name }}</span>'
								);
								?>
									</span>
							<# } else { #>
								<span class="byline">
								<?php
								printf(
									/* translators: %s: User's display name. */
									esc_html__( 'Revision by %s', 'newspack-revisions-enhanced' ),
									'<span class="author-name">{{ data.attributes.author.name }}</span>'
								);
								?>
									</span>
							<# } #>
								<span class="time-ago">{{ data.attributes.timeAgo }}</span>
								<span class="date">({{ data.attributes.dateShort }})</span>
								<# if ( data.attributes.nreMigration ) { #>
									<span class="nre-migration-badge">{{ data.attributes.nreMigration.name }}</span>
								<# } #>
							</div>
						</div>
					<# if ( 'to' === data.type && data.attributes.restoreUrl ) { #>
						<input  <?php if ( $post_locked ) { ?>
							disabled="disabled"
						<?php } else { ?>
							<# if ( data.attributes.current ) { #>
								disabled="disabled"
							<# } #>
						<?php } ?>
						<# if ( data.attributes.autosave ) { #>
							type="button" class="restore-revision button button-primary" value="<?php esc_attr_e( 'Restore This Autosave', 'newspack-revisions-enhanced' ); ?>" />
						<# } else { #>
							type="button" class="restore-revision button button-primary" value="<?php esc_attr_e( 'Restore This Revision', 'newspack-revisions-enhanced' ); ?>" />
						<# } #>
					<# } #>
				</div>
			<# if ( 'tooltip' === data.type ) { #>
				<div class="revisions-tooltip-arrow"><span></span></div>
			<# } #>
		<# } #>
		</script>

		<script id="tmpl-nre-revisions-diff" type="text/html">
			<div class="loading-indicator"><span class="spinner"></span></div>
			<div class="diff-error"><?php echo esc_html__( 'An error occurred while loading the comparison. Please refresh the page and try again.', 'newspack-revisions-enhanced' ); ?></div>
			<div class="diff">
			<# var _nreSection = ''; #>
			<# _.each( data.fields, function( field, index ) { #>
				<# var _nreType = ''; #>
				<# if ( field.id && field.id.indexOf( 'nre-tax-' ) === 0 ) { #>
					<# _nreType = 'tax'; #>
				<# } else if ( field.id && field.id.indexOf( 'nre-' ) === 0 ) { #>
					<# _nreType = 'meta'; #>
				<# } #>
				<# if ( _nreSection && _nreSection !== _nreType ) { #>
					</div>
					<# _nreSection = ''; #>
				<# } #>
				<# if ( _nreType === 'meta' && _nreSection !== 'meta' ) { #>
					<# _nreSection = 'meta'; #>
					<div class="nre-fields-section">
						<div class="nre-fields-section-header"><?php esc_html_e( 'Post Meta', 'newspack-revisions-enhanced' ); ?></div>
				<# } else if ( _nreType === 'tax' && _nreSection !== 'tax' ) { #>
					<# _nreSection = 'tax'; #>
					<div class="nre-fields-section">
						<div class="nre-fields-section-header"><?php esc_html_e( 'Taxonomy Terms', 'newspack-revisions-enhanced' ); ?></div>
				<# } #>
				<h2>{{ field.name }}</h2>
				{{{ field.diff }}}
			<# }); #>
			<# if ( _nreSection ) { #>
				</div>
			<# } #>
			</div>
		</script>
		<?php
	}

	/**
	 * Add Newspack theme body class to the revision screen.
	 *
	 * Scopes Part 2 of nre-revisions.css (core screen overrides).
	 * Disable via: add_filter( 'nre_newspack_revisions_theme', '__return_false' );
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function add_body_class( $classes ) {
		$screen = get_current_screen();
		if ( ! $screen || 'revision' !== $screen->id ) {
			return $classes;
		}

		if ( apply_filters( 'nre_newspack_revisions_theme', true ) ) {
			$classes .= ' nre-newspack-theme';
		}

		return $classes;
	}

	/**
	 * Print the localized migration settings as a JS global.
	 */
	private function print_settings() {
		if ( empty( self::$migrations ) ) {
			return;
		}

		// Re-index to a plain array sorted by timestamp.
		$migrations = array_values( self::$migrations );
		usort(
			$migrations,
			function ( $a, $b ) {
				return $a['timestamp'] - $b['timestamp'];
			}
		);

		?>
		<script>
			var _nreMigrationSettings = <?php echo wp_json_encode( $migrations ); ?>;
		</script>
		<?php
	}
}
