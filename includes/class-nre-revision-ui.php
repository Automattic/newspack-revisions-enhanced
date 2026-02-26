<?php
/**
 * NRE_Revision_UI — Add meta and taxonomy diff rows to the revision comparison screen.
 *
 * @package Newspack_Revisions_Enhanced
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add meta and taxonomy diff rows to the revision comparison screen.
 */
class NRE_Revision_UI {

	/**
	 * Meta revisions handler.
	 *
	 * @var NRE_Meta_Revisions
	 */
	private $meta_revisions;

	/**
	 * Taxonomy revisions handler.
	 *
	 * @var NRE_Taxonomy_Revisions
	 */
	private $taxonomy_revisions;

	/**
	 * Post type revisions handler.
	 *
	 * @var NRE_Post_Type_Revisions
	 */
	private $post_type_revisions;

	/**
	 * Constructor.
	 *
	 * @param NRE_Meta_Revisions      $meta_revisions      Meta revisions handler.
	 * @param NRE_Taxonomy_Revisions  $taxonomy_revisions  Taxonomy revisions handler.
	 * @param NRE_Post_Type_Revisions $post_type_revisions Post type revisions handler.
	 */
	public function __construct( NRE_Meta_Revisions $meta_revisions, NRE_Taxonomy_Revisions $taxonomy_revisions, NRE_Post_Type_Revisions $post_type_revisions ) {
		$this->meta_revisions      = $meta_revisions;
		$this->taxonomy_revisions  = $taxonomy_revisions;
		$this->post_type_revisions = $post_type_revisions;
	}

	/**
	 * Meta keys that get visual (non-text) diff rendering.
	 *
	 * @var string[]
	 */
	const VISUAL_META_KEYS = [ '_thumbnail_id' ];

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'wp_get_revision_ui_diff', [ $this, 'add_featured_image_diff_row' ], 1, 3 );
		add_filter( 'wp_get_revision_ui_diff', [ $this, 'add_post_type_diff_row' ], 5, 3 );
		add_filter( 'wp_get_revision_ui_diff', [ $this, 'add_meta_diff_rows' ], 10, 3 );
		add_filter( 'wp_get_revision_ui_diff', [ $this, 'add_visual_meta_diff_rows' ], 15, 3 );
		add_filter( 'wp_get_revision_ui_diff', [ $this, 'add_taxonomy_diff_rows' ], 20, 3 );
	}

	/**
	 * Prepend a featured image diff row to the top of the diff.
	 *
	 * Uses a non-nre- ID so the diff template treats it as a standalone
	 * section alongside Title, Content, and Excerpt.
	 *
	 * @param array   $return       Existing diff rows.
	 * @param WP_Post $compare_from The "from" revision (or false for first revision).
	 * @param WP_Post $compare_to   The "to" revision.
	 * @return array Modified diff rows.
	 */
	public function add_featured_image_diff_row( $return, $compare_from, $compare_to ) {
		$from_value = $compare_from ? get_post_meta( $compare_from->ID, '_thumbnail_id', true ) : '';
		$to_value   = get_post_meta( $compare_to->ID, '_thumbnail_id', true );

		if ( $from_value === $to_value ) {
			return $return;
		}

		$diff = $this->render_image_diff( (int) $from_value, (int) $to_value );

		if ( ! $diff ) {
			return $return;
		}

		array_unshift(
			$return,
			[
				'id'   => 'featured-image',
				'name' => '_thumbnail_id',
				'diff' => $diff,
			]
		);

		return $return;
	}

	/**
	 * Append a post type diff row when the post type changed between revisions.
	 *
	 * @param array   $return       Existing diff rows.
	 * @param WP_Post $compare_from The "from" revision (or false for first revision).
	 * @param WP_Post $compare_to   The "to" revision.
	 * @return array Modified diff rows.
	 */
	public function add_post_type_diff_row( $return, $compare_from, $compare_to ) {
		$from_type = '';
		$to_type   = get_post_meta( $compare_to->ID, NRE_Post_Type_Revisions::META_KEY, true );

		if ( $compare_from ) {
			$from_type = get_post_meta( $compare_from->ID, NRE_Post_Type_Revisions::META_KEY, true );
		}

		// Skip if both are identical or if snapshots don't exist yet.
		if ( $from_type === $to_type || ( '' === $from_type && '' === $to_type ) ) {
			return $return;
		}

		$from_label = $from_type ? $this->post_type_revisions->get_post_type_label( $from_type ) : '';
		$to_label   = $to_type ? $this->post_type_revisions->get_post_type_label( $to_type ) : '';

		$diff = wp_text_diff( $from_label, $to_label );

		if ( ! $diff ) {
			return $return;
		}

		$return[] = [
			'id'   => 'nre-post-type',
			'name' => __( 'Post Type', 'newspack-revisions-enhanced' ),
			'diff' => $diff,
		];

		return $return;
	}

	/**
	 * Append meta diff rows to the revision UI.
	 *
	 * @param array   $return       Existing diff rows.
	 * @param WP_Post $compare_from The "from" revision (or false for first revision).
	 * @param WP_Post $compare_to   The "to" revision.
	 * @return array Modified diff rows.
	 */
	public function add_meta_diff_rows( $return, $compare_from, $compare_to ) {
		$post_type = get_post_type( $compare_to->post_parent ? $compare_to->post_parent : $compare_to->ID );
		$meta_keys = $this->meta_revisions->get_tracked_meta_keys( $post_type );

		foreach ( $meta_keys as $meta_key ) {
			// Visual meta keys are handled separately.
			if ( in_array( $meta_key, self::VISUAL_META_KEYS, true ) ) {
				continue;
			}

			$from_value = '';
			$to_value   = '';

			if ( $compare_from ) {
				$from_value = $this->get_meta_display_value( $compare_from->ID, $meta_key );
			}

			$to_value = $this->get_meta_display_value( $compare_to->ID, $meta_key );

			// Skip if both are identical.
			if ( $from_value === $to_value ) {
				continue;
			}

			$diff = wp_text_diff( $from_value, $to_value );

			if ( ! $diff ) {
				continue;
			}

			$label    = $this->meta_revisions->get_meta_label( $meta_key, $post_type );
			$return[] = [
				'id'   => "nre-meta-{$meta_key}",
				'name' => $label,
				'diff' => $diff,
			];
		}

		return $return;
	}

	/**
	 * Append visual diff rows for meta keys that need rich rendering (e.g. images).
	 *
	 * @param array   $return       Existing diff rows.
	 * @param WP_Post $compare_from The "from" revision (or false for first revision).
	 * @param WP_Post $compare_to   The "to" revision.
	 * @return array Modified diff rows.
	 */
	public function add_visual_meta_diff_rows( $return, $compare_from, $compare_to ) {
		foreach ( self::VISUAL_META_KEYS as $meta_key ) {
			// _thumbnail_id is handled by add_featured_image_diff_row.
			if ( '_thumbnail_id' === $meta_key ) {
				continue;
			}

			$from_value = $compare_from ? get_post_meta( $compare_from->ID, $meta_key, true ) : '';
			$to_value   = get_post_meta( $compare_to->ID, $meta_key, true );

			if ( $from_value === $to_value ) {
				continue;
			}

			$diff = '';

			switch ( $meta_key ) {
				case '_thumbnail_id':
					$diff = $this->render_image_diff( (int) $from_value, (int) $to_value );
					break;
			}

			if ( ! $diff ) {
				continue;
			}

			$post_type = get_post_type( $compare_to->post_parent ? $compare_to->post_parent : $compare_to->ID );
			$label     = $this->meta_revisions->get_meta_label( $meta_key, $post_type );

			$return[] = [
				'id'   => "nre-meta-{$meta_key}",
				'name' => $label,
				'diff' => $diff,
			];
		}

		return $return;
	}

	/**
	 * Render a side-by-side image diff for featured image changes.
	 *
	 * Shows the actual image thumbnail, attachment ID, filename, and dimensions
	 * in a two-column layout matching the WP diff table structure.
	 *
	 * @param int $from_id Attachment ID for the "from" side (0 if none).
	 * @param int $to_id   Attachment ID for the "to" side (0 if none).
	 * @return string HTML diff output.
	 */
	private function render_image_diff( $from_id, $to_id ) {
		$from_html = $this->render_image_card( $from_id );
		$to_html   = $this->render_image_card( $to_id );

		$from_class = $from_id && ! $to_id ? 'diff-deletedline' : 'diff-context';
		$to_class   = $to_id && ! $from_id ? 'diff-addedline' : 'diff-context';

		if ( $from_id && $to_id && $from_id !== $to_id ) {
			$from_class = 'diff-deletedline';
			$to_class   = 'diff-addedline';
		}

		return sprintf(
			'<table class="diff"><colgroup><col class="content diffsplit left"><col class="content diffsplit middle"><col class="content diffsplit right"></colgroup><tbody><tr>'
			. '<td class="%s">%s</td>'
			. '<td class="diff-indicator"></td>'
			. '<td class="%s">%s</td>'
			. '</tr></tbody></table>',
			esc_attr( $from_class ),
			$from_html,
			esc_attr( $to_class ),
			$to_html
		);
	}

	/**
	 * Render an image card with thumbnail, metadata, and attachment info.
	 *
	 * @param int $attachment_id The attachment ID (0 for no image).
	 * @return string HTML for one side of the diff.
	 */
	private function render_image_card( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '<em>' . esc_html__( 'No featured image', 'newspack-revisions-enhanced' ) . '</em>';
		}

		$image = wp_get_attachment_image(
			$attachment_id,
			'medium',
			false,
			[
				'style' => 'max-width:100%;height:auto;display:block;margin-bottom:8px;',
			]
		);

		if ( ! $image ) {
			$image = '<em>' . sprintf(
				/* translators: %d: attachment ID */
				esc_html__( '[Deleted attachment #%d]', 'newspack-revisions-enhanced' ),
				$attachment_id
			) . '</em>';
		}

		$meta_lines   = [];
		$meta_lines[] = sprintf( '<strong>%s:</strong> %d', esc_html__( 'ID', 'newspack-revisions-enhanced' ), $attachment_id );

		$file = get_attached_file( $attachment_id );
		if ( $file ) {
			$meta_lines[] = sprintf( '<strong>%s:</strong> %s', esc_html__( 'File', 'newspack-revisions-enhanced' ), esc_html( wp_basename( $file ) ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
				$meta_lines[] = sprintf(
					'<strong>%s:</strong> %d &times; %d',
					esc_html__( 'Dimensions', 'newspack-revisions-enhanced' ),
					$metadata['width'],
					$metadata['height']
				);
			}
			if ( ! empty( $metadata['filesize'] ) ) {
				$meta_lines[] = sprintf(
					'<strong>%s:</strong> %s',
					esc_html__( 'Size', 'newspack-revisions-enhanced' ),
					size_format( $metadata['filesize'] )
				);
			}
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $alt ) {
			$meta_lines[] = sprintf( '<strong>%s:</strong> %s', esc_html__( 'Alt', 'newspack-revisions-enhanced' ), esc_html( $alt ) );
		}

		$attachment = get_post( $attachment_id );
		if ( $attachment && $attachment->post_title ) {
			$meta_lines[] = sprintf( '<strong>%s:</strong> %s', esc_html__( 'Title', 'newspack-revisions-enhanced' ), esc_html( $attachment->post_title ) );
		}

		$meta_html = '<div style="font-size:12px;line-height:1.6;margin-top:4px;">' . implode( '<br>', $meta_lines ) . '</div>';

		return '<div style="padding:8px;">' . $image . $meta_html . '</div>';
	}

	/**
	 * Append taxonomy diff rows to the revision UI.
	 *
	 * @param array   $return       Existing diff rows.
	 * @param WP_Post $compare_from The "from" revision (or false for first revision).
	 * @param WP_Post $compare_to   The "to" revision.
	 * @return array Modified diff rows.
	 */
	public function add_taxonomy_diff_rows( $return, $compare_from, $compare_to ) {
		$post_type  = get_post_type( $compare_to->post_parent ? $compare_to->post_parent : $compare_to->ID );
		$taxonomies = $this->taxonomy_revisions->get_tracked_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$from_ids = [];
			$to_ids   = $this->get_taxonomy_term_ids( $compare_to, $taxonomy );

			if ( $compare_from ) {
				$from_ids = $this->get_taxonomy_term_ids( $compare_from, $taxonomy );
			}

			if ( $from_ids === $to_ids ) {
				continue;
			}

			$diff = $this->render_taxonomy_diff( $from_ids, $to_ids, $taxonomy );

			if ( ! $diff ) {
				continue;
			}

			$tax_object = get_taxonomy( $taxonomy );
			$label      = $tax_object ? $tax_object->labels->name : ucwords( str_replace( [ '_', '-' ], ' ', $taxonomy ) );

			$return[] = [
				'id'   => "nre-tax-{$taxonomy}",
				'name' => $label,
				'diff' => $diff,
			];
		}

		return $return;
	}

	/**
	 * Get a display-ready meta value for a post/revision.
	 *
	 * Handles multi-value meta and complex values (arrays/objects).
	 *
	 * @param int    $post_id  Post or revision ID.
	 * @param string $meta_key The meta key.
	 * @return string Formatted value for diffing.
	 */
	public function get_meta_display_value( $post_id, $meta_key ) {
		$values = get_post_meta( $post_id, $meta_key );

		if ( empty( $values ) ) {
			$value = '';
		} elseif ( count( $values ) === 1 ) {
			$value = $this->format_single_meta_value( $values[0] );
		} else {
			// Multi-value: each on its own line.
			$formatted = array_map( [ $this, 'format_single_meta_value' ], $values );
			$value     = implode( "\n", $formatted );
		}

		/**
		 * Filter the display value for a meta key in the revision UI.
		 *
		 * @param string $value    The formatted display value.
		 * @param int    $post_id  The post/revision ID.
		 * @param string $meta_key The meta key.
		 */
		return apply_filters( 'nre_meta_display_value', $value, $post_id, $meta_key );
	}

	/**
	 * Format a single meta value for display.
	 *
	 * @param mixed $value The raw meta value.
	 * @return string Formatted string.
	 */
	private function format_single_meta_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return (string) $value;
	}

	/**
	 * Resolve term IDs to human-readable names.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @return string[] Term names (or fallback for deleted terms).
	 */
	public function resolve_term_names( $term_ids, $taxonomy ) {
		if ( empty( $term_ids ) ) {
			return [];
		}

		$names = [];

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );

			if ( is_wp_error( $term ) || ! $term ) {
				$names[] = sprintf( '[Deleted term #%d]', $term_id );
			} else {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * Render a side-by-side taxonomy term diff.
	 *
	 * Shows from/to term lists with added terms in `<ins>` and removed terms in `<del>`.
	 * Each term is a clickable link to its edit screen.
	 *
	 * @param int[]  $from_ids Term IDs on the "from" side.
	 * @param int[]  $to_ids   Term IDs on the "to" side.
	 * @param string $taxonomy Taxonomy name.
	 * @return string HTML diff table.
	 */
	private function render_taxonomy_diff( array $from_ids, array $to_ids, $taxonomy ) {
		$removed = array_diff( $from_ids, $to_ids );
		$added   = array_diff( $to_ids, $from_ids );

		// Build "from" side: removed terms wrapped in <del>, others plain.
		$from_items = [];
		foreach ( $from_ids as $term_id ) {
			$item = $this->render_term_item( $term_id, $taxonomy );
			if ( in_array( $term_id, $removed, true ) ) {
				$item = '<del>' . $item . '</del>';
			}
			$from_items[] = $item;
		}

		// Build "to" side: added terms wrapped in <ins>, others plain.
		$to_items = [];
		foreach ( $to_ids as $term_id ) {
			$item = $this->render_term_item( $term_id, $taxonomy );
			if ( in_array( $term_id, $added, true ) ) {
				$item = '<ins>' . $item . '</ins>';
			}
			$to_items[] = $item;
		}

		$from_html = empty( $from_items )
			? '<em>' . esc_html__( 'No terms', 'newspack-revisions-enhanced' ) . '</em>'
			: implode( '<br>', $from_items );

		$to_html = empty( $to_items )
			? '<em>' . esc_html__( 'No terms', 'newspack-revisions-enhanced' ) . '</em>'
			: implode( '<br>', $to_items );

		$from_class = ! empty( $removed ) ? 'diff-deletedline' : 'diff-context';
		$to_class   = ! empty( $added ) ? 'diff-addedline' : 'diff-context';

		return sprintf(
			'<table class="diff"><colgroup><col class="content diffsplit left"><col class="content diffsplit middle"><col class="content diffsplit right"></colgroup><tbody><tr>'
			. '<td class="%s"><div class="nre-term-list">%s</div></td>'
			. '<td class="diff-indicator"></td>'
			. '<td class="%s"><div class="nre-term-list">%s</div></td>'
			. '</tr></tbody></table>',
			esc_attr( $from_class ),
			$from_html,
			esc_attr( $to_class ),
			$to_html
		);
	}

	/**
	 * Render a single term as a clickable link or deleted-term fallback.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy name.
	 * @return string HTML for the term.
	 */
	private function render_term_item( $term_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) || ! $term ) {
			return sprintf(
				'<span class="nre-term-deleted">[%s]</span>',
				sprintf(
					/* translators: %d: term ID */
					esc_html__( 'Deleted term #%d', 'newspack-revisions-enhanced' ),
					$term_id
				)
			);
		}

		$edit_link = get_edit_term_link( $term_id, $taxonomy );

		if ( $edit_link ) {
			return sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $edit_link ),
				esc_html( $term->name )
			);
		}

		return esc_html( $term->name );
	}

	/**
	 * Get taxonomy term IDs for a post object.
	 *
	 * For revisions, reads from stored meta. For parent posts, reads live data.
	 *
	 * @param WP_Post $post     The post or revision object.
	 * @param string  $taxonomy The taxonomy name.
	 * @return int[] Term IDs.
	 */
	public function get_taxonomy_term_ids( $post, $taxonomy ) {
		// If this is a revision, read stored snapshot.
		if ( 'revision' === $post->post_type ) {
			$stored = get_post_meta( $post->ID, NRE_TAX_META_PREFIX . $taxonomy, true );

			if ( is_array( $stored ) ) {
				return array_map( 'intval', $stored );
			}

			return [];
		}

		// Parent post: read live taxonomy data.
		return $this->taxonomy_revisions->get_live_term_ids( $post->ID, $taxonomy );
	}
}
