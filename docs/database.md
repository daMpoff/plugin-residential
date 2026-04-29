# Database

The plugin creates two tables using WordPress `dbDelta()`.

Schema version is stored in:

```text
wscosm_db_version
```

Current schema version:

```text
2
```

## `{prefix}wscosm_osm_object`

Stores OSM features converted to GeoJSON.

Important columns:

| Column | Purpose |
| --- | --- |
| `city_id` | WordPress city post ID. |
| `object_key` | Stable unique object key, usually `{osm_type}:{osm_id}`. |
| `osm_type` | OSM element type, such as `node`, `way`, or `relation`. |
| `osm_id` | OSM element ID. |
| `wscosm_kind` | Plugin object category used for layers and styling. |
| `geom_type` | GeoJSON geometry type. |
| `geometry_json` | GeoJSON geometry JSON. |
| `properties_json` | GeoJSON properties JSON. |
| `bbox_s`, `bbox_w`, `bbox_n`, `bbox_e` | Geometry envelope for viewport reads. |
| `fetched_gmt` | Last fetch time. |
| `ergo_status` | Reserved ergonomics processing state. |

Indexes:

| Index | Purpose |
| --- | --- |
| `uk_city_object (city_id, object_key)` | Upsert identity. |
| `city_kind (city_id, wscosm_kind)` | Layer/category queries. |
| `city_ergo (city_id, ergo_status)` | Future ergonomics processing. |
| `city_id_id (city_id, id)` | Ordered city reads. |
| `city_bbox (city_id, bbox_e, bbox_w, bbox_n, bbox_s)` | Bbox intersection reads. |

## Bbox Read Query

Feature reads use envelope intersection:

```sql
WHERE city_id = ?
  AND bbox_s IS NOT NULL
  AND bbox_w IS NOT NULL
  AND bbox_n IS NOT NULL
  AND bbox_e IS NOT NULL
  AND bbox_e >= ?
  AND bbox_w <= ?
  AND bbox_n >= ?
  AND bbox_s <= ?
ORDER BY id ASC
LIMIT ?
```

This returns objects whose stored geometry envelope intersects the requested viewport.

## Upsert Behavior

`WSCOSM_Feature_Store::upsert_collection()`:

- skips invalid features;
- computes geometry envelopes;
- falls back to the request bbox only when geometry envelope is unavailable;
- inserts by `city_id + object_key`;
- updates existing rows;
- preserves larger geometry JSON when duplicate rows provide a smaller partial geometry;
- expands stored bbox envelopes with `LEAST()` and `GREATEST()`.

## `{prefix}wscosm_log`

Stores plugin logs.

Important columns:

| Column | Purpose |
| --- | --- |
| `created_gmt` | Log time. |
| `level` | `error`, `warning`, `info`, etc. |
| `scope` | Subsystem or operation. |
| `message` | Human-readable message. |
| `context` | Optional JSON context. |
| `city_id` | Related city, when available. |

Logs can be viewed from the WordPress admin tools page registered by `WSCOSM_Admin`.
