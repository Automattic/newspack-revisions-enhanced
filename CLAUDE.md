# Newspack Revisions Enhanced

WordPress plugin that adds revision tracking for post meta, taxonomy terms, and post type changes. Includes a migration context system to tag revisions created during data migrations, with a React-based dashboard for viewing migration history and diffs.

## Project Structure

```
newspack-revisions-enhanced.php    # Main plugin file, bootstraps all classes
includes/
  class-nre-meta-revisions.php     # Tracks post meta in revisions (discovers all meta keys from DB)
  class-nre-taxonomy-revisions.php # Tracks taxonomy term assignments in revisions
  class-nre-post-type-revisions.php # Tracks post type changes in revisions
  class-nre-revision-ui.php        # Adds meta/taxonomy/post-type diff rows to WP revisions screen
  class-nre-migration-context.php  # Static API: NRE_Migration_Context::start()/stop() for tagging revisions
  class-nre-migration-ui.php       # Adds migration badges to WP revisions screen, Newspack theme overrides
  class-nre-migration-rollback.php # Rollback API for reverting posts to pre-migration state
  class-nre-migration-dashboard.php # React dashboard: REST API, admin page, HTML export endpoint
src/dashboard/
  index.js                         # React app entry point
  style.scss                       # Dashboard styles (Newspack design tokens)
  utils.js                         # API helpers: fetchMigrations, downloadCsv, downloadReport
  components/
    MigrationList.js               # Sidebar listing all migrations
    MigrationDetail.js             # Detail panel: stats, post table, actions
    PostTable.js                   # Searchable, paginated post table (50/page)
    StatsBar.js                    # Created/Updated/Total stat cards
    DiffModal.js                   # Modal showing per-field diffs for a post
    RollbackButton.js              # Single-post rollback button
    BulkRollback.js                # Bulk rollback for entire migration
assets/
  css/nre-revisions.css            # Styles for WP revisions screen (Newspack theme overrides)
  js/nre-revisions.js              # Backbone overrides for WP revisions UI (migration badges)
```

## Build & Development

```bash
npm install && npm run build    # Build React dashboard → build/dashboard/
npm run start                   # Watch mode for development
composer install                # Install PHPCS dev dependencies
composer phpcs                  # Run WordPress coding standards check
composer phpcbf                 # Auto-fix coding standards issues
```

The JS build uses `@wordpress/scripts` — no custom webpack config. Output goes to `build/dashboard/`.

## Coding Standards

- **PHP**: WordPress Coding Standards via PHPCS (`.phpcs.xml.dist`)
  - Prefixes: `nre_` / `NRE_` for all globals
  - Text domain: `newspack-revisions-enhanced`
  - PHP 7.4+, WordPress 6.4+
  - Short array syntax `[]` is allowed
- **JS**: Standard `@wordpress/scripts` ESLint config
- **CSS**: SCSS with Newspack design tokens (see below)

## Newspack Design Tokens

Used throughout CSS — keep consistent when adding styles:

| Token | Value |
|-------|-------|
| Accent | `#003da5` |
| Text primary | `#1e1e1e` |
| Text secondary | `#757575` |
| Text tertiary | `#949494` |
| Border | `#ddd` |
| Border light | `#f0f0f0` |
| Background light | `#f7f7f7` |
| Panel radius | `2px` |
| Button radius | `3px` |
| Badge radius | `2px` |
| Transition | `125ms ease-in-out` |

## Architecture

### Revision Tracking

The plugin hooks into WordPress's existing revision system:
- `wp_post_revision_meta_keys` filter — registers meta keys for revision tracking
- `_wp_put_post_revision` action — saves taxonomy and post type snapshots to revision meta
- `wp_save_post_revision_post_has_changed` filter — forces revision creation when meta/taxonomy/type changed
- `wp_get_revision_ui_diff` filter — adds diff rows for meta, taxonomies, post type, and featured image

Meta keys are auto-discovered from the database (all distinct meta keys for the post type, minus excluded noise prefixes). Override with the `nre_revision_meta_keys` filter.

### Migration Context

Wrap data migration code with `NRE_Migration_Context::start('name')` / `::stop()`. Any revisions created in between are tagged with the `nre_migration` taxonomy. The taxonomy is hidden (not public) and uses term meta to store the migration timestamp.

When revisions are deleted, `on_revision_delete()` cleans up orphaned migration term relationships and empty terms automatically.

### Migration Dashboard

- Admin page under **Tools > Migrations** (`tools_page_nre-migrations`)
- React app with sidebar (migration list) + detail panel
- REST API at `/wp-json/nre/v1/migrations/` and `/wp-json/nre/v1/migrations/<term_id>`
- HTML export via `admin_post_nre_export_migration` (not REST — REST can't serve file downloads cleanly)
- Export generates self-contained HTML with inline CSS, accordion diffs, tabs, and print-to-PDF support

### Newspack Theme for Revisions Screen

The WP revisions screen (`revision.php`) gets Newspack-styled overrides via `.nre-newspack-theme` body class. Core WP revisions CSS is never dequeued — overrides layer on top with higher specificity.

Disable with: `add_filter( 'nre_newspack_revisions_theme', '__return_false' )`

## Key Filters

| Filter | Description |
|--------|-------------|
| `nre_revision_meta_keys` | Modify tracked meta keys array `($keys, $post_type)` |
| `nre_auto_detect_rest_meta` | Enable/disable auto-detection of REST meta `(bool, $post_type)` |
| `nre_meta_label` | Customize display label for a meta key `($label, $meta_key, $post_type)` |
| `nre_meta_display_value` | Transform meta value for display in diffs `($value, $post_id, $meta_key)` |
| `nre_tracked_taxonomies` | Modify tracked taxonomies array `($taxonomies, $post_type)` |
| `nre_newspack_revisions_theme` | Toggle Newspack theme on revisions screen `(bool)` |

## Common Patterns

- All classes follow the `register_hooks()` pattern — instantiated in `nre_init()`, hooks registered explicitly
- `NRE_Migration_Context` is the only static class (global state for migration tagging)
- REST endpoints use `check_permission()` requiring `edit_others_posts` capability
- File downloads use `admin_post_` actions (not REST) to avoid JSON envelope issues
- Dashboard JS uses `wp.apiFetch` with `nreDashboard` localized data (REST URL, nonces, export URL)

## Git Conventions

- Do not add `Co-Authored-By` trailers to commits
- `vendor/`, `node_modules/`, and `build/` are gitignored
- `composer.lock` is committed (ensures consistent PHPCS versions)
