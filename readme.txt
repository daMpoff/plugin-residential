=== WorldStat - Courtyard OSM Map ===
Contributors: ergonosphera
Tags: world statistics, openstreetmap, overpass, leaflet, cities, ergonomics
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds OpenStreetMap courtyard context to World Statistics Platform city pages and country tabs.

== Description ==

WorldStat - Courtyard OSM Map extends World Statistics Platform with a courtyard environment map for cities.

The plugin combines:

* saved courtyard/building polygons from WorldStat Ergonomics;
* OpenStreetMap objects from Overpass API;
* persistent OSM object storage in the WordPress database;
* bbox-based loading for map viewports;
* bounded Voronoi courtyard previews that can be saved into WorldStat Ergonomics;
* a protected live scan action for refreshing OSM data.

On normal page loads the frontend reads OSM features from the local database by viewport bbox. Live Overpass requests are used only for explicit scans or permitted fallback paths, and successful scans are stored in the database for later page loads.

== Dependencies ==

This plugin is designed as an extension for the WorldStat stack:

* World Statistics Platform
* WorldStat Cities
* WorldStat Ergonomics

WorldStat Ergonomics is optional for tab registration, but required for courtyard polygon overlays and yard ergonomics popups.

== OpenStreetMap and Overpass ==

Overpass queries are executed server-side by WordPress. The query bbox is always normalized and limited before it reaches Overpass.

The default Overpass endpoint is:

`https://overpass-api.de/api/interpreter`

You can override it with the `wscosm_overpass_interpreter_url` filter.

The plugin requests selected OSM objects that are useful for courtyard context:

* buildings and building parts;
* benches;
* waste baskets;
* street lamps;
* playgrounds;
* pedestrian paths and related ways;
* green landuse areas.

== Database Storage ==

OSM objects are stored in:

* `{prefix}wscosm_osm_object`

Logs are stored in:

* `{prefix}wscosm_log`

The object table stores geometry, properties, OSM identifiers, object bbox, fetch time, and ergonomics processing status. Schema upgrades are handled by `WSCOSM_DB::maybe_upgrade()`.

== Security ==

Public visitors can read saved local OSM data.

Live Overpass scans are restricted by default to users who can manage options or edit the city post. This can be customized with the `wscosm_can_live_overpass` filter.

== REST API ==

Main endpoints:

* `GET /wp-json/wscosm/v1/city/{id}/features`
* `GET /wp-json/wscosm/v1/scan-progress`
* `GET /wp-json/wscosm/v1/city/{id}/yard-ergo-at`
* `POST /wp-json/wscosm/v1/city/{id}/voronoi-yards`

The `features` endpoint accepts `south`, `west`, `north`, `east`, `source`, `refresh`, and `progress_id` parameters.

Use `source=local` for database reads and `source=live&refresh=1` for authorized Overpass scans.

== Configuration ==

Useful options and filters:

* `wscosm_radius_km` - default city-center bbox radius in kilometers.
* `wscosm_overpass_interpreter_url` - override Overpass endpoint.
* `wscosm_max_radius_km` - maximum half-size for scan bbox.
* `wscosm_scan_max_center_offset_km` - maximum allowed scan offset from city center.
* `wscosm_max_osm_buildings` - cap building features returned from one Overpass response.
* `wscosm_persist_osm_objects` - enable or disable OSM object persistence.
* `wscosm_can_live_overpass` - customize live scan permissions.
* `wscosm_agent_debug_log` - enable internal NDJSON debug logging.
* `wscosm_return_saved_features_after_scan` - return DB rows after scan instead of fresh Overpass payload.

== Installation ==

1. Install and activate World Statistics Platform.
2. Install and activate WorldStat Cities.
3. Install and activate WorldStat Ergonomics if courtyard polygons are needed.
4. Upload this plugin directory to `wp-content/plugins/`.
5. Activate WorldStat - Courtyard OSM Map.
6. Open a city page or the country tab to view stored data.
7. Use the scan button as an authorized user to fetch OSM data for the visible map bbox.

== Development Docs ==

Additional documentation is available in the `docs/` directory:

* `docs/architecture.md`
* `docs/rest-api.md`
* `docs/database.md`
* `docs/operations.md`

== Changelog ==

= 1.2.12 =
* Tightened Voronoi courtyard cells to each building's local 50 m envelope.
* Excluded `building:part` objects from Voronoi seeds to avoid slicing one building into artificial yards.

= 1.2.11 =
* Added bounded Voronoi courtyard previews from OSM buildings.
* Added batch saving of generated cells into WorldStat Ergonomics `wsp_yard` polygons.

= 1.2.10 =
* Keep already visible OSM objects on the map after scanning a new bbox; scan results are merged instead of replacing layers.

= 1.2.9 =
* Added WordPress REST nonce headers to map REST requests so authorized local scans keep the current user session.

= 1.2.8 =
* Fixed country tab registration when plugin load order changes after activation.
* Made WorldStat Ergonomics optional for tab registration; it is still used for courtyard overlays when active.

= 1.2.7 =
* Added bbox-based local loading for initial map views.
* Persisted Overpass scan results into the database.
* Restricted live Overpass scans to authorized users.
* Added bbox indexes for faster database reads.
* Disabled internal debug file logging by default.

= 1.2.3 =
* Initial public plugin structure for WorldStat courtyard OSM maps.

== Disclaimer ==

OpenStreetMap data is community-maintained and may be incomplete or outdated. This plugin provides contextual map layers and does not replace the core ergonomics scoring methodology.
