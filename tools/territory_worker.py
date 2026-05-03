#!/usr/bin/env python3
"""
Vector territory worker for stored OSM GeoJSON.

This is intentionally outside the WordPress request path. It uses GEOS through
Shapely and an STRtree spatial index, which is the normal way to keep this kind
of geometry workload from freezing PHP/Apache.

Usage:
  python tools/territory_worker.py --input storage/berezniki-osm.geojson --output storage/berezniki-yards.geojson
"""

from __future__ import annotations

import argparse
import json
import math
import sys
import time
from pathlib import Path
from typing import Any, Iterable

try:
    from shapely.geometry import GeometryCollection, MultiPoint, mapping, shape
    from shapely.ops import transform, unary_union, voronoi_diagram
    from shapely.strtree import STRtree
    from shapely.validation import make_valid
except ModuleNotFoundError as exc:
    print(
        "Missing dependency. Install with: python -m pip install -r tools/requirements-territory.txt",
        file=sys.stderr,
    )
    raise SystemExit(2) from exc


METERS_PER_DEGREE = 111_320.0


def prop_tag(props: dict[str, Any], name: str) -> str:
    return str(props.get(f"tag_{name}") or props.get(name) or "").lower()


def is_building(props: dict[str, Any]) -> bool:
    kind = str(props.get("wscosm_kind") or "")
    return kind.startswith("bldg_") and kind != "bldg_part"


def is_hard_obstacle(props: dict[str, Any]) -> bool:
    kind = str(props.get("wscosm_kind") or "")
    highway = prop_tag(props, "highway")
    barrier = prop_tag(props, "barrier")
    if kind in {"water", "railway", "landuse_industrial", "landuse_railway", "restricted_area"}:
        return True
    if highway in {"motorway", "motorway_link", "trunk", "trunk_link", "primary", "primary_link", "secondary"}:
        return True
    if barrier in {"fence", "wall", "retaining_wall"}:
        return True
    return False


def local_projectors(origin_lat: float):
    cos_lat = max(0.15, min(1.0, abs(math.cos(math.radians(origin_lat)))))

    def to_meters(x: float, y: float, z: float | None = None):
        return (x * METERS_PER_DEGREE * cos_lat, y * METERS_PER_DEGREE)

    def to_lonlat(x: float, y: float, z: float | None = None):
        return (round(x / (METERS_PER_DEGREE * cos_lat), 7), round(y / METERS_PER_DEGREE, 7))

    return to_meters, to_lonlat


def clean_geom(geom):
    if geom.is_empty:
        return geom
    try:
        geom = make_valid(geom)
    except Exception:
        geom = geom.buffer(0)
    return geom


def flatten_polygons(geom) -> list[Any]:
    if geom.is_empty:
        return []
    if geom.geom_type == "Polygon":
        return [geom]
    if geom.geom_type == "MultiPolygon":
        return list(geom.geoms)
    if geom.geom_type == "GeometryCollection":
        out = []
        for part in geom.geoms:
            out.extend(flatten_polygons(part))
        return out
    return []


def load_features(path: Path) -> list[dict[str, Any]]:
    with path.open("r", encoding="utf-8") as fh:
        data = json.load(fh)
    return list(data.get("features") or [])


def feature_bbox(features: Iterable[dict[str, Any]]) -> tuple[float, float, float, float]:
    west, south, east, north = 180.0, 90.0, -180.0, -90.0
    seen = False
    for feature in features:
        geom = feature.get("geometry")
        if not geom:
            continue
        b = shape(geom).bounds
        west, south = min(west, b[0]), min(south, b[1])
        east, north = max(east, b[2]), max(north, b[3])
        seen = True
    if not seen:
        raise ValueError("Input GeoJSON has no geometries")
    return west, south, east, north


def strtree_query(tree: STRtree, geom, geoms: list[Any]) -> list[Any]:
    result = tree.query(geom)
    if len(result) == 0:
        return []
    first = result[0]
    if not hasattr(first, "geom_type"):
        return [geoms[int(i)] for i in result]
    return list(result)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--max-distance", type=float, default=35.0)
    parser.add_argument("--min-area", type=float, default=10.0)
    args = parser.parse_args()

    started = time.perf_counter()
    features = load_features(args.input)
    west, south, east, north = feature_bbox(features)
    origin_lat = (south + north) / 2.0
    to_meters, to_lonlat = local_projectors(origin_lat)

    buildings = []
    hard_obstacles = []
    for feature in features:
        props = dict(feature.get("properties") or {})
        geom_data = feature.get("geometry")
        if not geom_data:
            continue
        geom_m = clean_geom(transform(to_meters, shape(geom_data)))
        if geom_m.is_empty:
            continue
        if is_building(props):
            for poly in flatten_polygons(geom_m):
                buildings.append((poly, props))
        elif is_hard_obstacle(props):
            if geom_m.geom_type in {"LineString", "MultiLineString"}:
                geom_m = geom_m.buffer(6.0)
            hard_obstacles.append(geom_m)

    if not buildings:
        raise ValueError("No suitable buildings in input")

    building_polys = [b[0] for b in buildings]
    building_union = unary_union(building_polys)
    obstacle_union = unary_union(hard_obstacles + [building_union]) if hard_obstacles else building_union
    centers = [poly.representative_point() for poly in building_polys]
    points = MultiPoint(centers)
    envelope = building_union.envelope.buffer(args.max_distance * 3.0)
    cells = list(voronoi_diagram(points, envelope=envelope, edges=False).geoms)
    cell_tree = STRtree(cells)

    out_features = []
    for idx, (building, props) in enumerate(buildings, start=1):
        center = centers[idx - 1]
        candidates = strtree_query(cell_tree, center, cells)
        if not candidates:
            candidates = cells
        cell = next((candidate for candidate in candidates if candidate.covers(center)), None)
        if cell is None:
            cell = min(candidates, key=lambda candidate: candidate.distance(center))

        yard = cell.intersection(building.buffer(args.max_distance)).difference(obstacle_union)
        yard = clean_geom(yard)
        for poly in flatten_polygons(yard):
            if poly.area < args.min_area:
                continue
            geom_ll = transform(to_lonlat, poly)
            object_key = props.get("object_key") or props.get("wscosm_osm_id") or str(idx)
            out_features.append(
                {
                    "type": "Feature",
                    "geometry": mapping(geom_ll),
                    "properties": {
                        "object_key": f"py_territory_{object_key}",
                        "building_id": object_key,
                        "source": "python_shapely_voronoi",
                        "calculation_scope": "city_vector",
                        "area_m2": round(poly.area, 1),
                        "max_distance_m": args.max_distance,
                        "wscosm_kind": props.get("wscosm_kind", ""),
                    },
                }
            )

        if idx % 100 == 0:
            print(f"processed buildings {idx}/{len(buildings)}", flush=True)

    result = {
        "type": "FeatureCollection",
        "features": out_features,
        "stats": {
            "source": "python_shapely_voronoi",
            "input_features": len(features),
            "buildings": len(buildings),
            "hard_obstacles": len(hard_obstacles),
            "generated_polygons": len(out_features),
            "elapsed_sec": round(time.perf_counter() - started, 2),
        },
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as fh:
        json.dump(result, fh, ensure_ascii=False)
    print(json.dumps(result["stats"], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
