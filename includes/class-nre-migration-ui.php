<?php
/**
 * NRE_Migration_UI — Admin UI for migration context: badges, filter bar, scripts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			plugins_url( 'assets/js/nre-revisions.js', dirname( __FILE__ ) ),
			[ 'revisions' ],
			NRE_VERSION,
			true
		);
	}

	/**
	 * Print the custom Underscore template, inline CSS, and localized migration settings.
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
		$this->print_styles();
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
						<strong id="diff-title-from"><?php _ex( 'From:', 'Followed by post revision info' ); ?></strong>
					<# } else if ( 'to' === data.type ) { #>
						<strong id="diff-title-to"><?php _ex( 'To:', 'Followed by post revision info' ); ?></strong>
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
									__( 'Autosave by %s' ),
									'<span class="author-name">{{ data.attributes.author.name }}</span>'
								);
								?>
									</span>
							<# } else if ( data.attributes.current ) { #>
								<span class="byline">
								<?php
								printf(
									/* translators: %s: User's display name. */
									__( 'Current Revision by %s' ),
									'<span class="author-name">{{ data.attributes.author.name }}</span>'
								);
								?>
									</span>
							<# } else { #>
								<span class="byline">
								<?php
								printf(
									/* translators: %s: User's display name. */
									__( 'Revision by %s' ),
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
							type="button" class="restore-revision button button-primary" value="<?php esc_attr_e( 'Restore This Autosave' ); ?>" />
						<# } else { #>
							type="button" class="restore-revision button button-primary" value="<?php esc_attr_e( 'Restore This Revision' ); ?>" />
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
			<div class="diff-error"><?php _e( 'An error occurred while loading the comparison. Please refresh the page and try again.' ); ?></div>
			<div class="diff">
			<# var _nreInSection = false; #>
			<# _.each( data.fields, function( field, index ) { #>
				<# var _nreIsCustom = field.id && field.id.indexOf( 'nre-' ) === 0; #>
				<# if ( _nreIsCustom && ! _nreInSection ) { #>
					<# _nreInSection = true; #>
					<div class="nre-fields-section">
						<div class="nre-fields-section-header"><?php esc_html_e( 'Post Meta', 'newspack-revisions-enhanced' ); ?></div>
				<# } else if ( ! _nreIsCustom && _nreInSection ) { #>
					<# _nreInSection = false; #>
					</div>
				<# } #>
				<h2>{{ field.name }}</h2>
				{{{ field.diff }}}
			<# }); #>
			<# if ( _nreInSection ) { #>
				</div>
			<# } #>
			</div>
		</script>
		<?php
	}

	/**
	 * Print inline CSS for migration badges and sidebar.
	 */
	private function print_styles() {
		?>
		<style>
			/* Badge in from/to panels and tooltip */
			.nre-migration-badge {
				display: inline-block;
				background: #003da5;
				color: #fff;
				font-size: 12px;
				line-height: 1;
				padding: 2px 8px;
				border-radius: 2px;
				margin-left: 6px;
				vertical-align: middle;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.02em;
			}

			.revisions-tooltip .nre-migration-badge {
				margin-left: 0;
				margin-top: 4px;
			}

			/* Wrapper: sidebar + diff side by side */
			.nre-diff-wrapper {
				display: flex;
				align-items: flex-start;
			}

			.nre-diff-wrapper > .revisions-diff-frame {
				flex: 1;
				min-width: 0;
			}

			/* Sidebar — Newspack design language */
			.nre-migration-sidebar {
				width: 260px;
				flex-shrink: 0;
				border: 1px solid #ddd;
				border-radius: 6px;
				box-shadow: 0 1px 10px rgba(0, 0, 0, 0.07);
				background: #fff;
				margin-right: 16px;
				overflow: hidden;
				position: sticky;
				top: 52px;
			}

			.nre-sidebar-header {
				padding: 12px 16px;
				font-size: 14px;
				font-weight: 600;
				color: #1e1e1e;
				border-bottom: 1px solid #ddd;
				line-height: 1.4;
			}

			/* Sidebar items */
			.nre-sidebar-item {
				display: block;
				width: 100%;
				padding: 10px 16px;
				border: none;
				border-bottom: 1px solid #f0f0f0;
				background: none;
				text-align: left;
				cursor: pointer;
				font-size: 14px;
				color: #1e1e1e;
				line-height: 1.4;
				transition: background-color 125ms ease-in-out, box-shadow 125ms ease-in-out;
			}

			.nre-sidebar-item:last-of-type {
				border-bottom: none;
			}

			.nre-sidebar-item:hover {
				background: #f7f7f7;
			}

			.nre-sidebar-item:focus {
				outline: 2px solid #003da5;
				outline-offset: -2px;
			}

			.nre-sidebar-item.active {
				background: #f7f7f7;
				box-shadow: inset 4px 0 0 0 #003da5;
			}

			.nre-sidebar-item-name {
				display: block;
				font-weight: 600;
				font-size: 13px;
			}

			.nre-sidebar-item-meta {
				display: block;
				font-size: 12px;
				color: #6c6c6c;
				margin-top: 2px;
			}

			.nre-sidebar-item-date {
				display: block;
				font-size: 12px;
				color: #949494;
				margin-top: 1px;
			}

			/* Navigation controls */
			.nre-sidebar-nav {
				border-top: 1px solid #ddd;
				padding: 10px 16px;
			}

			.nre-sidebar-nav-row {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 6px;
			}

			.nre-sidebar-nav .nre-nav-position {
				font-size: 12px;
				color: #6c6c6c;
				white-space: nowrap;
			}

			.nre-sidebar-nav .button {
				min-width: 28px;
				padding: 0 6px;
				line-height: 24px;
				border-radius: 3px;
			}

			/* Custom fields section grouping in the diff */
			.nre-fields-section {
				border: 1px solid #ddd;
				border-radius: 6px;
				overflow: hidden;
				box-shadow: none;
				background: #fff;
				margin-top: 16px;
				padding: 0 12px 12px;
			}

			.nre-fields-section-header {
				font-size: 14px;
				font-weight: 600;
				color: #1e1e1e;
				padding: 12px 16px;
				margin: 0 -12px 0;
				border-bottom: 1px solid #ddd;
				background: #f7f7f7;
			}

			.nre-fields-section h2 {
				font-size: 14px;
				font-weight: 600;
				padding: 10px 16px 4px;
				color: #6c6c6c;
			}
		</style>
		<?php
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
		usort( $migrations, function ( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		?>
		<script>
			var _nreMigrationSettings = <?php echo wp_json_encode( $migrations ); ?>;
		</script>
		<?php
	}
}
