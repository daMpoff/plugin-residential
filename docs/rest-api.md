# REST API

Namespace:

```text
wscosm/v1
```

## Get City Features

```http
GET /wp-json/wscosm/v1/city/{id}/features
```

Returns a GeoJSON FeatureCollection for a city.

### Query Parameters

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `source` | string | `auto` | `local`, `live`, or `auto`. |
| `refresh` | bool/string/int | false | When truthy, performs a live Overpass scan. |
| `south` | number | optional | Bbox south latitude. |
| `west` | number | optional | Bbox west longitude. |
| `north` | number | optional | Bbox north latitude. |
| `east` | number | optional | Bbox east longitude. |
| `progress_id` | string | optional | Scan progress ID used by the frontend poller. |

### `source=local`

Reads only from `{prefix}wscosm_osm_object`.

Example:

```http
GET /wp-json/wscosm/v1/city/123/features?source=local&south=53.20&west=34.30&north=53.30&east=34.45
```

### `source=live&refresh=1`

Runs an authorized Overpass scan for the bbox, persists the features, and returns the fresh GeoJSON response.

Example:

```http
GET /wp-json/wscosm/v1/city/123/features?source=live&refresh=1&south=53.20&west=34.30&north=53.30&east=34.45
```

Permission is controlled by `WSCOSM_REST::can_live_overpass()`.

Default allowed users:

- users with `manage_options`;
- users who can `edit_post` for the city.

Customize:

```php
add_filter( 'wscosm_can_live_overpass', function ( $allowed, $city_id ) {
    return $allowed;
}, 10, 2 );
```

### `source=auto`

Reads from the database first. If no local features are found and the current user can perform live scans, it falls back to Overpass.

Public frontend code uses `source=local` for normal loading.

## Scan Progress

```http
GET /wp-json/wscosm/v1/scan-progress?progress_id={id}
```

Returns transient-backed scan progress:

```json
{
  "phase": "saving",
  "total": 1000,
  "saved": 250,
  "current": 300,
  "message": ""
}
```

Known phases:

- `unknown`
- `overpass`
- `saving`
- `done`
- `error`

## Yard Ergonomics At Point

```http
GET /wp-json/wscosm/v1/city/{id}/yard-ergo-at?lat={lat}&lng={lng}
```

Looks up the imported courtyard polygon at a map point and returns a small HTML fragment for a popup.

Response:

```json
{
  "found": true,
  "yard_id": 456,
  "html": "<div>...</div>"
}
```

This endpoint is public because it only reads published city/yard data.

## Save Voronoi Yards

```http
POST /wp-json/wscosm/v1/city/{id}/voronoi-yards
```

Persists generated bounded Voronoi cells as `wsp_yard` posts when WorldStat Ergonomics is active.

The request body is JSON:

```json
{
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Polygon", "coordinates": [] },
      "properties": {
        "object_key": "way:123",
        "wscosm_osm_el_type": "way",
        "wscosm_osm_id": 123,
        "center": { "lat": 53.2, "lng": 34.3 }
      }
    }
  ]
}
```

The endpoint accepts up to 500 features per request. The frontend sends larger saves in batches.

Response:

```json
{
  "saved": 250,
  "skipped": 0,
  "errors": []
}
```

Permission matches live scans: `manage_options`, `edit_post(city_id)`, or the `wscosm_can_live_overpass` filter.
