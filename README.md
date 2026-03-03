# Newspack Revisions Enhanced

Read-only revision tracking for post meta, taxonomies, and post type changes.

## Overview

WordPress core only tracks `post_title`, `post_content`, and `post_excerpt` in revisions. This plugin extends that to also snapshot post meta, taxonomy term assignments, and post type — so editors and migration scripts can see a full history of what changed.

All data is stored as revision meta and displayed in the native revision comparison screen. Nothing is written back to posts; tracking is strictly read-only.

## What Gets Tracked

**Post meta** — Auto-detected from REST-registered keys (`show_in_rest => true`) that don't already have core's `revisions_enabled`. `_thumbnail_id` is always included.

**Taxonomies** — All public taxonomies attached to the post type. Term IDs are snapshotted per revision, and the diff shows term names (with a `[Deleted term #N]` fallback for removed terms).

**Post type** — Detects when a post changes type (e.g. `post` → `page`) and shows the before/after labels.

**Featured image** — Side-by-side visual diff with thumbnails, filenames, dimensions, filesize, and alt text. Missing attachments fall back to `[Deleted attachment #N]`.

## Revision UI Enhancements

The revision comparison screen is extended with:

- **Post Meta section** — grouped rows for each tracked meta key, with human-readable labels
- **Featured Image diff** — visual cards with thumbnails instead of raw attachment IDs
- **Taxonomy diff** — term names listed per taxonomy
- **Post type diff** — label comparison when the post type changed
- **Migration badge** — shown on revision meta panels when a revision was created during a migration context, with a tooltip showing migration name and timestamp

## Migration Context

Tag revisions created by import scripts or batch operations with a named migration context.

```php
NRE_Migration_Context::start( 'Batch import Q3 2024' );

// All revisions created here are tagged with the migration name and timestamp.
wp_update_post( $post_data );
wp_set_post_terms( $post_id, $terms, 'category' );

NRE_Migration_Context::stop();
```

For migrators that use raw `$wpdb` queries (which bypass WordPress hooks), use the `before_update`/`after_update` helpers to manually create revisions:

```php
NRE_Migration_Context::start( 'Raw SQL migration' );

NRE_Migration_Context::before_update( $post_id ); // Creates untagged baseline revision.
$wpdb->update( $wpdb->posts, [ 'post_content' => 'New content' ], [ 'ID' => $post_id ] );
NRE_Migration_Context::after_update( $post_id );  // Creates tagged migration revision.

NRE_Migration_Context::stop();
```

What this does:

- Every revision created between `start()` and `stop()` is tagged with the migration name and a Unix timestamp in revision meta.
- An `nre_migration` taxonomy term is assigned to the parent post, making migration-touched posts filterable in the admin list.
- The revision screen shows a migration sidebar for filtering and navigating revisions by migration.

### API

| Method | Description |
|--------|-------------|
| `NRE_Migration_Context::start( string $name )` | Begin a migration context. |
| `NRE_Migration_Context::stop()` | End the current migration context. |
| `NRE_Migration_Context::get_context()` | Returns `array{name: string, timestamp: int}` or `null` if inactive. |
| `NRE_Migration_Context::before_update( int $post_id )` | Create an untagged baseline revision before a raw SQL update. Only creates one if the post has no revisions yet. No-op outside a migration context. |
| `NRE_Migration_Context::after_update( int $post_id )` | Create a tagged migration revision after a raw SQL update. Clears post cache first so the revision captures the current DB state. No-op outside a migration context. |

## Filters Reference

| Filter | Description | Default | Example |
|--------|-------------|---------|---------|
| `nre_revision_meta_keys` | Add or remove tracked meta keys. Receives `string[] $keys` and `string $post_type`. | Auto-detected REST keys + `_thumbnail_id` | `add_filter( 'nre_revision_meta_keys', fn( $keys ) => array_merge( $keys, [ '_my_key' ] ), 10, 2 );` |
| `nre_auto_detect_rest_meta` | Disable auto-detection of REST-registered meta. Receives `bool` and `string $post_type`. | `true` | `add_filter( 'nre_auto_detect_rest_meta', '__return_false' );` |
| `nre_meta_label` | Customize display labels for meta keys. Receives `string $label`, `string $meta_key`, `string $post_type`. | Key name or registered label | `add_filter( 'nre_meta_label', fn( $label, $key ) => $key === '_price' ? 'Price' : $label, 10, 3 );` |
| `nre_meta_display_value` | Format meta values before display. Receives `string $value`, `int $post_id`, `string $meta_key`. | Raw value | `add_filter( 'nre_meta_display_value', fn( $v, $id, $k ) => $k === '_price' ? '$' . $v : $v, 10, 3 );` |
| `nre_tracked_taxonomies` | Add or remove tracked taxonomies. Receives `string[] $taxonomies` and `string $post_type`. | All public taxonomies | `add_filter( 'nre_tracked_taxonomies', fn( $t ) => [ 'category', 'post_tag' ], 10, 2 );` |
| `nre_newspack_revisions_theme` | Toggle Newspack-styled revision screen. | `true` | `add_filter( 'nre_newspack_revisions_theme', '__return_false' );` |

## Requirements

- WordPress 6.4+
- PHP 7.4+
