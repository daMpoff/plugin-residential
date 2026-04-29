# WorldStat - Courtyard OSM Map

WordPress plugin for the WorldStat ecosystem. It adds OpenStreetMap courtyard context to city maps and country pages, stores fetched OSM objects in the WordPress database, and loads map features by viewport bbox.

## What It Does

The plugin shows courtyard-related layers on WorldStat city and country views:

- saved courtyard/building polygons from WorldStat Ergonomics;
- OpenStreetMap buildings and building parts;
- benches, street lamps, waste baskets, playgrounds, paths, and green areas;
- optional yard ergonomics popups for building objects;
- layer controls and building-type legends in Leaflet.

The important behavior is:

- normal page loads read OSM features from the local database by bbox;
- authorized scans request Overpass API by the current map bbox;
- successful Overpass responses are converted to GeoJSON and persisted;
- later page loads reuse the saved database rows.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- World Statistics Platform
- WorldStat Cities
- WorldStat Ergonomics for courtyard polygon overlays

## How Loading Works

1. The map initializes and computes the current Leaflet viewport bbox.
2. The frontend requests `/wp-json/wscosm/v1/city/{id}/features` with `source=local` and bbox params.
3. The REST handler reads intersecting objects from `{prefix}wscosm_osm_object`.
4. If an authorized user clicks the scan button, the frontend requests the same endpoint with `source=live&refresh=1`.
5. The server normalizes the bbox, queries Overpass, converts the response to GeoJSON, and upserts features into the database.
6. Future `source=local` requests load those saved objects.

## Repository Layout

```text
assets/
  css/city-osm-map.css
  js/city-osm-map.js
  js/country-tab-yards.js
includes/
  class-wscosm-db.php
  class-wscosm-feature-store.php
  class-wscosm-overpass.php
  class-wscosm-rest.php
  class-wscosm-city-map.php
  class-wscosm-country-tab.php
  ...
docs/
  architecture.md
  database.md
  operations.md
  rest-api.md
worldstat-courtyard-osm.php
readme.txt
```

## Main REST Endpoints

- `GET /wp-json/wscosm/v1/city/{id}/features`
- `GET /wp-json/wscosm/v1/scan-progress`
- `GET /wp-json/wscosm/v1/city/{id}/yard-ergo-at`

See [REST API docs](docs/rest-api.md) for parameters and examples.

## Security Model

Public users can read saved local data.

Live Overpass scans are protected. By default, a user must be able to `manage_options` or `edit_post` for the city. Override with:

```php
add_filter( 'wscosm_can_live_overpass', function ( $allowed, $city_id ) {
    return $allowed;
}, 10, 2 );
```

## Configuration

Common filters:

- `wscosm_overpass_interpreter_url`
- `wscosm_max_radius_km`
- `wscosm_scan_max_center_offset_km`
- `wscosm_max_osm_buildings`
- `wscosm_persist_osm_objects`
- `wscosm_can_live_overpass`
- `wscosm_agent_debug_log`
- `wscosm_return_saved_features_after_scan`

Common option:

- `wscosm_radius_km`

## Development Checks

JavaScript syntax:

```bash
node --check assets/js/city-osm-map.js
node --check assets/js/country-tab-yards.js
```

PHP syntax, when `php` is available:

```bash
php -l worldstat-courtyard-osm.php
php -l includes/class-wscosm-rest.php
php -l includes/class-wscosm-feature-store.php
```

## Docs

- [Architecture](docs/architecture.md)
- [REST API](docs/rest-api.md)
- [Database](docs/database.md)
- [Operations](docs/operations.md)

## License

GPL v2 or later.
