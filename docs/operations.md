# Operations

This document covers common operational tasks for installing, scanning, debugging, and tuning the plugin.

## Installation

1. Install and activate World Statistics Platform.
2. Install and activate WorldStat Cities.
3. Install and activate WorldStat Ergonomics if courtyard polygons are needed.
4. Upload this plugin directory to `wp-content/plugins/`.
5. Activate the plugin.
6. Visit a city page or a country page with the courtyard tab.

On activation the plugin creates its database tables. On later loads it runs `WSCOSM_DB::maybe_upgrade()` to add new schema changes.

## First Data Load

Saved local OSM data will be empty until a live scan is performed.

To scan:

1. Log in as a user with permission to scan.
2. Open a country page and select a city in the courtyard tab.
3. Move/zoom the map to the target area.
4. Click `Scan`.
5. Wait for Overpass and database save progress to finish.
6. Reload as a public visitor to confirm data is loaded from the database.

## Permissions

Live scans are intentionally protected because they call Overpass and write database rows.

Default access:

- `manage_options`;
- `edit_post` for the city.

Override:

```php
add_filter( 'wscosm_can_live_overpass', function ( $allowed, $city_id ) {
    return current_user_can( 'edit_posts' ) || $allowed;
}, 10, 2 );
```

## Overpass Endpoint

Default:

```text
https://overpass-api.de/api/interpreter
```

Override:

```php
add_filter( 'wscosm_overpass_interpreter_url', function () {
    return 'https://overpass.kumi.systems/api/interpreter';
} );
```

Respect the usage policy of the selected Overpass instance.

## Limits and Tuning

Maximum scan bbox radius:

```php
add_filter( 'wscosm_max_radius_km', function () {
    return 5.0;
} );
```

Maximum allowed viewport offset from city center:

```php
add_filter( 'wscosm_scan_max_center_offset_km', function ( $km ) {
    return max( 25.0, $km );
} );
```

Maximum building features kept from one Overpass response:

```php
add_filter( 'wscosm_max_osm_buildings', function () {
    return 20000;
} );
```

Disable persistence:

```php
add_filter( 'wscosm_persist_osm_objects', '__return_false' );
```

## Debugging

WordPress admin page:

```text
Tools -> Courtyard OSM
```

It shows plugin logs and saved object counts.

Internal NDJSON debug logging is disabled by default. Enable only for short investigations:

```php
add_filter( 'wscosm_agent_debug_log', '__return_true' );
```

The debug file is written beside the plugin parent directory as `debug-97fecd.log`.

## Verification Commands

JavaScript:

```bash
node --check assets/js/city-osm-map.js
node --check assets/js/country-tab-yards.js
```

PHP, if available:

```bash
php -l worldstat-courtyard-osm.php
php -l includes/class-wscosm-rest.php
php -l includes/class-wscosm-feature-store.php
```

Git:

```bash
git status --short
git log -1 --oneline
```

## Common Symptoms

### The map has no OSM objects

Likely causes:

- no live scan has been run yet;
- the requested viewport has no saved objects;
- the scan user lacks permission;
- Overpass returned an error;
- the city has missing or invalid coordinates.

### Scan button is missing

The current user does not have live scan permission.

### Scan fails for large viewport

The bbox may exceed the configured max radius or be too far from the city center. Zoom in or move closer to the city, or adjust the filters carefully.

### Page loads are slow

Check row count in `{prefix}wscosm_osm_object`, database indexes, and viewport size. The plugin uses bbox filtering and limits returned features to protect frontend rendering.
