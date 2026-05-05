#!/usr/bin/env python3
"""
Buffer building contours in meters (same algorithm as WSCOSM_REST::offset_ring_lonlat).

Usage:
  python3 tools/buffer_building_yards.py --radius-m 35 < buildings.geojson > yards.geojson
  python3 tools/buffer_building_yards.py --radius-m 35 path/to/buildings.geojson

Stdin: GeoJSON FeatureCollection of OSM building polygons (wscosm_kind bldg_*).
Stdout: GeoJSON FeatureCollection of buffer yard features.
Stderr: lines "WSCOSM_PROGRESS <current> <total> buffer" for coarse progress.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from typing import Any, List, Mapping, MutableMapping, Sequence, Tuple


def _deg2rad(d: float) -> float:
    return d * math.pi / 180.0


def _rad2deg(r: float) -> float:
    return r * 180.0 / math.pi


def _walk_coords_centroid(coords: Any, acc: MutableMapping[str, float]) -> None:
    if not isinstance(coords, list) or not coords:
        return
    x0, x1 = coords[0], coords[1] if len(coords) > 1 else None
    if not isinstance(x0, list) and x1 is not None and not isinstance(x1, list):
        if isinstance(x0, (int, float)) and isinstance(x1, (int, float)):
            acc["slon"] += float(x0)
            acc["slat"] += float(x1)
            acc["n"] += 1
        return
    for c in coords:
        _walk_coords_centroid(c, acc)


def geometry_representative_latlng(geom: Mapping[str, Any]) -> Optional[Tuple[float, float]]:
    acc = {"slon": 0.0, "slat": 0.0, "n": 0}
    _walk_coords_centroid(geom.get("coordinates"), acc)
    n = int(acc["n"])
    if n <= 0:
        return None
    lon = acc["slon"] / n
    lat = acc["slat"] / n
    return (lat, lon)


def offset_ring_lonlat(
    ring_lonlat: Sequence[Any], radius_m: float, origin_lat: float
) -> List[List[float]]:
    ring: List[List[float]] = []
    for pt in ring_lonlat:
        if isinstance(pt, (list, tuple)) and len(pt) >= 2:
            if isinstance(pt[0], (int, float)) and isinstance(pt[1], (int, float)):
                ring.append([float(pt[0]), float(pt[1])])
    if len(ring) < 3:
        return []
    first = ring[0]
    last = ring[-1]
    if abs(first[0] - last[0]) < 1e-12 and abs(first[1] - last[1]) < 1e-12:
        ring = ring[:-1]
    n = len(ring)
    if n < 3:
        return []

    cos_lat = max(0.15, min(1.0, abs(math.cos(_deg2rad(origin_lat)))))
    r_earth = 6371000.0
    pts: List[dict] = []
    for p in ring:
        pts.append(
            {
                "x": r_earth * _deg2rad(p[0]) * cos_lat,
                "y": r_earth * _deg2rad(p[1]),
            }
        )

    area2 = 0.0
    for i in range(n):
        j = (i + 1) % n
        area2 += pts[i]["x"] * pts[j]["y"] - pts[j]["x"] * pts[i]["y"]
    ccw = area2 > 0

    out: List[List[float]] = []
    for i in range(n):
        ip = (i - 1 + n) % n
        inn = (i + 1) % n
        prev_p = pts[ip]
        cur = pts[i]
        next_p = pts[inn]

        e1x = cur["x"] - prev_p["x"]
        e1y = cur["y"] - prev_p["y"]
        e2x = next_p["x"] - cur["x"]
        e2y = next_p["y"] - cur["y"]

        len1 = math.hypot(e1x, e1y)
        len2 = math.hypot(e2x, e2y)
        if len1 < 1e-9 or len2 < 1e-9:
            continue
        e1x /= len1
        e1y /= len1
        e2x /= len2
        e2y /= len2

        if ccw:
            n1x, n1y = e1y, -e1x
            n2x, n2y = e2y, -e2x
        else:
            n1x, n1y = -e1y, e1x
            n2x, n2y = -e2y, e2x

        bx = n1x + n2x
        by = n1y + n2y
        bl = math.hypot(bx, by)
        if bl < 1e-9:
            bx, by = n1x, n1y
            bl = math.hypot(bx, by)
        bx /= max(1e-9, bl)
        by /= max(1e-9, bl)

        dot = max(-0.999, min(0.999, n1x * n2x + n1y * n2y))
        scale = radius_m / max(0.2, math.sqrt((1.0 + dot) / 2.0))
        scale = min(scale, radius_m * 6.0)

        ox = cur["x"] + bx * scale
        oy = cur["y"] + by * scale
        lon = _rad2deg(ox / (r_earth * cos_lat))
        lat = _rad2deg(oy / r_earth)
        out.append([round(lon, 7), round(lat, 7)])

    if len(out) < 3:
        return []
    out.append(out[0])
    return out


def buffer_geometry_from_building(building_geom: Mapping[str, Any], radius_m: float) -> Optional[dict]:
    gtype = str(building_geom.get("type") or "")
    coords = building_geom.get("coordinates")
    if not isinstance(coords, list):
        return None
    origin = geometry_representative_latlng(building_geom)
    origin_lat = float(origin[0]) if origin else 0.0

    if gtype == "Polygon":
        if not coords or not isinstance(coords[0], list):
            return None
        ring = offset_ring_lonlat(coords[0], radius_m, origin_lat)
        if len(ring) < 4:
            return None
        return {"type": "Polygon", "coordinates": [ring]}

    if gtype == "MultiPolygon":
        out_polys: List[List[List[float]]] = []
        for poly in coords:
            if not isinstance(poly, list) or not poly or not isinstance(poly[0], list):
                continue
            ring = offset_ring_lonlat(poly[0], radius_m, origin_lat)
            if len(ring) >= 4:
                out_polys.append([ring])
        if not out_polys:
            return None
        return {"type": "MultiPolygon", "coordinates": out_polys}

    return None


def is_valid_polygon_zone(geometry: Mapping[str, Any]) -> bool:
    if geometry.get("type") != "Polygon":
        return False
    coords = geometry.get("coordinates")
    if not isinstance(coords, list) or not coords:
        return False
    rings0 = coords[0]
    if not isinstance(rings0, list) or len(rings0) < 4:
        return False
    for pair in rings0:
        if not isinstance(pair, (list, tuple)) or len(pair) < 2:
            return False
        lon, lat = float(pair[0]), float(pair[1])
        if lon < -180 or lon > 180 or lat < -90 or lat > 90:
            return False
    return True


def sanitize_kind(s: str) -> str:
    return "".join(ch for ch in s.lower() if ch.isalnum() or ch == "_")


def sanitize_key_wp(s: str) -> str:
    s = str(s or "").lower()
    return "".join(c for c in s if c.isalnum() or c in "_-")


def eligible_building(props: Mapping[str, Any], geom: Mapping[str, Any]) -> bool:
    kind = sanitize_kind(str(props.get("wscosm_kind") or ""))
    if not kind.startswith("bldg_") or kind == "bldg_part":
        return False
    gmt = str(geom.get("type") or "")
    return gmt in ("Polygon", "MultiPolygon")


def emit_progress(stderr, current: int, total: int, phase: str) -> None:
    print(f"WSCOSM_PROGRESS {current} {total} {phase}", file=stderr)
    stderr.flush()


def process_fc(fc: Mapping[str, Any], radius_m: float, stderr) -> dict:
    features_in = fc.get("features") if isinstance(fc.get("features"), list) else []
    eligible_idx: List[Any] = []
    for feat in features_in:
        if not isinstance(feat, dict) or feat.get("type") != "Feature":
            continue
        props = feat.get("properties")
        geom = feat.get("geometry")
        if not isinstance(props, dict) or not isinstance(geom, dict):
            continue
        if not eligible_building(props, geom):
            continue
        eligible_idx.append((feat, geom, props))

    total = len(eligible_idx)
    out_features: List[dict] = []
    progress_every = max(1, total // 200) if total > 400 else max(1, min(50, total)) if total else 1

    for i, (_, geom, props) in enumerate(eligible_idx):
        # Progress must reflect processed buildings, not only emitted features.
        if total and (i + 1) % progress_every == 0:
            emit_progress(stderr, i + 1, total, "buffer")

        zone_geom = buffer_geometry_from_building(geom, radius_m)
        if zone_geom is None or not is_valid_polygon_zone(zone_geom):
            continue

        origin = geometry_representative_latlng(geom)
        if origin is None:
            continue
        lat_o, lng_o = origin

        object_key = str(props.get("object_key") or "").strip()
        osm_type = sanitize_key_wp(str(props.get("wscosm_osm_el_type") or ""))
        try:
            osm_id = int(props.get("wscosm_osm_id") or 0)
        except (TypeError, ValueError):
            osm_id = 0
        if not object_key and osm_type and osm_id > 0:
            object_key = f"{osm_type}:{osm_id}"
        if not object_key:
            continue

        name = str(props.get("name") or "").strip()
        title = name if name else f"Generated yard {object_key}"
        kind = sanitize_kind(str(props.get("wscosm_kind") or ""))

        out_features.append(
            {
                "type": "Feature",
                "geometry": zone_geom,
                "properties": {
                    "object_key": object_key,
                    "wscosm_osm_el_type": osm_type,
                    "wscosm_osm_id": osm_id,
                    "wscosm_kind": kind,
                    "name": name,
                    "title": title,
                    "center": {"lat": lat_o, "lng": lng_o},
                    "method": "building_contour_buffer_bulk",
                    "radius_m": radius_m,
                },
            }
        )

    if total:
        emit_progress(stderr, total, total, "buffer")

    return {"type": "FeatureCollection", "features": out_features}


def main() -> int:
    ap = argparse.ArgumentParser(description="Buffer building polygons to yard zones (WSCOSM).")
    ap.add_argument("--radius-m", type=float, required=True)
    ap.add_argument("input_path", nargs="?", help="GeoJSON file; default stdin")
    args = ap.parse_args()
    radius_m = max(5.0, min(200.0, float(args.radius_m)))

    data: Any
    if args.input_path:
        with open(args.input_path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
    else:
        stdin = sys.stdin.read()
        if not stdin.strip():
            print("{}", file=sys.stderr)
            return 1
        data = json.loads(stdin)

    if not isinstance(data, dict) or data.get("type") != "FeatureCollection":
        print(json.dumps({"error": "expected FeatureCollection"}), file=sys.stderr)
        return 1

    out = process_fc(data, radius_m, sys.stderr)
    sys.stdout.write(json.dumps(out, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
