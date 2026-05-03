/**
 * Вкладка страны «Придомовые»: выбор города, Leaflet + график (после AJAX вкладки).
 */
(function ($) {
	'use strict';

	var cfg = typeof wscosmCountryTab === 'undefined' ? null : wscosmCountryTab;
	if (!cfg) {
		return;
	}

	function restFetchOptions() {
		var opts = { credentials: 'same-origin', cache: 'no-store' };
		if (cfg.restNonce) {
			opts.headers = { 'X-WP-Nonce': cfg.restNonce };
		}
		return opts;
	}

	function restPostOptions(data) {
		var opts = restFetchOptions();
		opts.method = 'POST';
		opts.headers = opts.headers || {};
		opts.headers['Content-Type'] = 'application/json';
		opts.body = JSON.stringify(data || {});
		return opts;
	}

	/** Цвета полигонов/точек по категории building=* (совпадает с порядком в PHP). */
	var BLDS = {
		bldg_yes: { fill: '#94a3b8', stroke: '#475569' },
		bldg_residential: { fill: '#c4b5fd', stroke: '#5b21b6' },
		bldg_commercial: { fill: '#fda4af', stroke: '#9f1239' },
		bldg_civic: { fill: '#7dd3fc', stroke: '#0369a1' },
		bldg_cultural: { fill: '#f0abfc', stroke: '#a21caf' },
		bldg_industrial: { fill: '#fdba74', stroke: '#c2410c' },
		bldg_office: { fill: '#93c5fd', stroke: '#1d4ed8' },
		bldg_religious: { fill: '#fcd34d', stroke: '#b45309' },
		bldg_garage: { fill: '#a8a29e', stroke: '#44403c' },
		bldg_agricultural: { fill: '#86efac', stroke: '#166534' },
		bldg_transport: { fill: '#67e8f9', stroke: '#0e7490' },
		bldg_health: { fill: '#fca5a5', stroke: '#b91c1c' },
		bldg_education: { fill: '#bfdbfe', stroke: '#1e40af' },
		bldg_hotel: { fill: '#e9d5ff', stroke: '#6b21a8' },
		bldg_sport: { fill: '#4ade80', stroke: '#15803d' },
		bldg_minor: { fill: '#d6d3d1', stroke: '#57534e' },
		bldg_part: { fill: '#cbd5e1', stroke: '#334155' },
		bldg_other: { fill: '#9ca3af', stroke: '#374151' }
	};

	var TERRITORY_CONFIG = {
		method: 'server_constrained_distance_allocation',
		preset: 'high_accuracy',
		cellSizeMeters: 2,
		maxDistanceMeters: 35,
		minAreaM2: 10,
		useLineOfSightCheck: true,
		useFootwaysAsBarriers: false,
		smoothToleranceMeters: 0.75,
		minHoleAreaM2: 22,
		simplifyTolerance: 0.00001,
		roadBuffers: {
			motorway: 18,
			motorway_link: 12,
			trunk: 16,
			trunk_link: 10,
			primary: 15,
			primary_link: 9,
			secondary: 12,
			tertiary: 10,
			unclassified: 8,
			residential: 7,
			living_street: 6,
			service: 4,
			footway: 3,
			pedestrian: 4,
			path: 3,
			default: 8
		},
		maxGridCells: 260000,
		maxDebugCells: 1500,
		debug: false
	};

	function isBldgKind(kind) {
		return kind && String(kind).indexOf('bldg_') === 0;
	}

	function bldgColors(kind) {
		return BLDS[kind] || BLDS.bldg_other;
	}

	function yardColorForIndex(v) {
		if (v === null || v === undefined || v === '' || isNaN(parseFloat(v))) {
			return { fill: '#cbd5e1', stroke: '#64748b' };
		}
		var val = parseFloat(v);
		var t = val / 100;
		if (t < 0) t = 0;
		if (t > 1) t = 1;
		var stops = ['#dc2626', '#facc15', '#16a34a'];
		var n = stops.length - 1;
		var seg = t * n;
		var i = Math.floor(seg);
		var u = seg - i;
		function hex(h) {
			h = h.replace('#', '');
			if (h.length === 3) {
				h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
			}
			return {
				r: parseInt(h.substr(0, 2), 16),
				g: parseInt(h.substr(2, 2), 16),
				b: parseInt(h.substr(4, 2), 16)
			};
		}
		if (i >= n) {
			return { fill: stops[n], stroke: '#1e293b' };
		}
		var a = hex(stops[i]);
		var b = hex(stops[i + 1]);
		var r = Math.round(a.r + (b.r - a.r) * u);
		var g = Math.round(a.g + (b.g - a.g) * u);
		var bl = Math.round(a.b + (b.b - a.b) * u);
		return { fill: 'rgb(' + r + ',' + g + ',' + bl + ')', stroke: '#1e293b' };
	}

	function buildYardMeansChart(baseChart, features) {
		var chart = $.extend(true, {}, baseChart || {});
		var keys = chart.dimensionKeys || [];
		var sums = {};
		var counts = {};
		keys.forEach(function (key) {
			sums[key] = 0;
			counts[key] = 0;
		});
		(features || []).forEach(function (feat) {
			var props = (feat && feat.properties) || {};
			keys.forEach(function (key) {
				var value = parseFloat(props['dim_' + key]);
				if (!isNaN(value) && value > 0) {
					sums[key] += value;
					counts[key]++;
				}
			});
		});
		if (chart.datasets && chart.datasets[0]) {
			chart.datasets[0].data = keys.map(function (key) {
				return counts[key] > 0 ? Math.round((sums[key] / counts[key]) * 100) / 100 : 0;
			});
		}
		return chart;
	}

	function osmStyle(kind, geomType) {
		if (isBldgKind(kind)) {
			var bc = bldgColors(kind);
			if (geomType === 'LineString' || geomType === 'MultiLineString') {
				return {
					color: bc.stroke,
					weight: 2,
					opacity: 0.9,
					fillOpacity: 0,
					fill: false
				};
			}
			if (geomType === 'Polygon' || geomType === 'MultiPolygon') {
				return {
					fillColor: bc.fill,
					color: bc.stroke,
					weight: 0.8,
					opacity: 1,
					fillOpacity: 0.45
				};
			}
		}
		if (geomType === 'LineString' || geomType === 'MultiLineString') {
			return {
				color: kind === 'path' ? '#1d4ed8' : kind === 'playground' ? '#db2777' : '#64748b',
				weight: kind === 'path' ? 4 : 2,
				opacity: 1,
				fillOpacity: 0,
				fill: false
			};
		}
		if (geomType === 'Polygon' || geomType === 'MultiPolygon') {
			return {
				fillColor: kind === 'playground' ? '#fbcfe8' : '#bbf7d0',
				color: kind === 'playground' ? '#be185d' : '#15803d',
				weight: 1.5,
				opacity: 1,
				fillOpacity: kind === 'landuse_green' ? 0.28 : 0.35
			};
		}
		return { color: '#64748b', weight: 2 };
	}

	function osmPointStyle(kind) {
		if (isBldgKind(kind)) {
			var bc = bldgColors(kind);
			return {
				radius: 5,
				fillColor: bc.fill,
				color: '#fff',
				weight: 2,
				opacity: 1,
				fillOpacity: 0.9
			};
		}
		var colors = {
			bench: '#92400e',
			light: '#ca8a04',
			playground: '#db2777',
			waste_basket: '#475569',
			other: '#64748b'
		};
		return {
			radius: kind === 'playground' ? 7 : 5,
			fillColor: colors[kind] || colors.other,
			color: '#fff',
			weight: 2,
			opacity: 1,
			fillOpacity: 0.88
		};
	}

	function osmPopup(props, withErgoBlock, ergoCtx) {
		var parts = ['<div class="wscosm-popup">'];
		if (props.name) {
			parts.push('<strong>' + String(props.name) + '</strong><br>');
		}
		parts.push('<span class="wscosm-popup-kind">' + String(props.wscosm_kind || '') + '</span>');
		Object.keys(props).forEach(function (k) {
			if (k.indexOf('tag_') !== 0) return;
			parts.push('<br><code>' + k.replace(/^tag_/, '') + '</code>: ' + String(props[k]));
		});
		if (withErgoBlock && ergoCtx) {
			var et =
				(ergoCtx.i18n && ergoCtx.i18n.ergoTitle) ||
				(cfg.i18n && cfg.i18n.ergoTitle) ||
				'';
			var loadTxt =
				(ergoCtx.i18n && ergoCtx.i18n.ergoLoading) || (cfg.i18n && cfg.i18n.ergoLoading) || '…';
			parts.push(
				'<div class="wscosm-ergo-block" style="margin-top:0.65rem;padding-top:0.5rem;border-top:1px solid #e5e7eb;">'
			);
			if (et) {
				parts.push('<strong class="wscosm-ergo-h">' + $('<div/>').text(et).html() + '</strong>');
			}
			parts.push(
				'<div class="wscosm-ergo-inject">' + $('<div/>').text(loadTxt).html() + '</div></div>'
			);
		}
		parts.push('</div>');
		return parts.join('');
	}

	function layerCenterLatLng(layer) {
		if (typeof layer.getLatLng === 'function') {
			return layer.getLatLng();
		}
		if (typeof layer.getBounds === 'function') {
			var b = layer.getBounds();
			if (b && b.isValid && b.isValid()) {
				return b.getCenter();
			}
		}
		return null;
	}

	function bindBuildingErgoPopup(layer, props, ergoCtx) {
		if (!ergoCtx || !ergoCtx.yardErgoAtUrl || !ergoCtx.cityId) {
			return;
		}
		layer.on('popupopen', function () {
			var ll = layerCenterLatLng(layer);
			var pu = layer.getPopup && layer.getPopup();
			var el = pu && pu.getElement && pu.getElement();
			var inject = el && el.querySelector('.wscosm-ergo-inject');
			if (!ll || !inject) {
				return;
			}
			if (typeof layer._wscosmErgoHtml === 'string') {
				inject.innerHTML = layer._wscosmErgoHtml;
				return;
			}
			var loadTxt =
				(ergoCtx.i18n && ergoCtx.i18n.ergoLoading) || (cfg.i18n && cfg.i18n.ergoLoading) || '…';
			inject.innerHTML = $('<div/>').text(loadTxt).html();
			var base = ergoCtx.yardErgoAtUrl;
			var u =
				base +
				(base.indexOf('?') >= 0 ? '&' : '?') +
				'lat=' +
				encodeURIComponent(ll.lat) +
				'&lng=' +
				encodeURIComponent(ll.lng);
			fetch(u, restFetchOptions())
				.then(function (r) {
					if (!r.ok) {
						return Promise.reject(new Error('http'));
					}
					return r.json();
				})
				.then(function (data) {
					var html = data && data.html ? data.html : '';
					layer._wscosmErgoHtml = html;
					inject.innerHTML = html;
				})
				.catch(function () {
					var err =
						(ergoCtx.i18n && ergoCtx.i18n.ergoError) || (cfg.i18n && cfg.i18n.ergoError) || '';
					inject.innerHTML = '<p class="wsp-error">' + $('<div/>').text(err).html() + '</p>';
				});
		});
	}

	function ensureBldgGroup(kinds, kindOrder, kind) {
		if (!isBldgKind(kind)) {
			return null;
		}
		if (!kinds[kind]) {
			kinds[kind] = L.featureGroup();
			if (kindOrder.indexOf(kind) === -1) {
				kindOrder.push(kind);
			}
		}
		return kinds[kind];
	}

	function unionBounds(groups) {
		var b = L.latLngBounds();
		var ok = false;
		groups.forEach(function (g) {
			if (!g || !g.getLayers().length) return;
			var gb = g.getBounds();
			if (gb && gb.isValid()) {
				b.extend(gb);
				ok = true;
			}
		});
		return ok ? b : null;
	}

	function createEmptyKindGroups() {
		var bOrder = (cfg.buildingKindOrder || []).slice();
		var kindOrder = bOrder.concat([
			'landuse_green',
			'playground',
			'bench',
			'light',
			'waste_basket',
			'path'
		]);
		var kinds = {};
		bOrder.forEach(function (k) {
			kinds[k] = L.featureGroup();
		});
		kinds.landuse_green = L.layerGroup();
		kinds.playground = L.layerGroup();
		kinds.bench = L.layerGroup();
		kinds.light = L.layerGroup();
		kinds.waste_basket = L.layerGroup();
		kinds.path = L.layerGroup();
		return { kinds: kinds, kindOrder: kindOrder };
	}

	function simpleHash(s) {
		var h = 2166136261;
		for (var i = 0; i < s.length; i++) {
			h ^= s.charCodeAt(i);
			h += (h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24);
		}
		return (h >>> 0).toString(16);
	}

	function osmFeatureKey(feat) {
		if (!feat || !feat.properties) {
			return '';
		}
		var props = feat.properties || {};
		var osmType = props.wscosm_osm_el_type || '';
		var osmId = props.wscosm_osm_id || '';
		if (osmType && osmId) {
			return String(osmType) + ':' + String(osmId);
		}
		return 'h:' + simpleHash(JSON.stringify([props.wscosm_kind || '', feat.geometry || null]));
	}

	function fillOsmFeaturesIntoKinds(kinds, kindOrder, ofc, ergoCtx, seenKeys, featureStore) {
		if (!ofc || !ofc.features) {
			return 0;
		}
		var added = 0;
		ofc.features.forEach(function (feat) {
			var featureKey = osmFeatureKey(feat);
			if (featureStore && featureKey) {
				featureStore[featureKey] = feat;
			}
			if (seenKeys && featureKey && seenKeys[featureKey]) {
				return;
			}
			var kind = (feat.properties && feat.properties.wscosm_kind) || 'bldg_other';
			if (!kind || kind === 'other') {
				kind = 'bldg_other';
			}
			var target = kinds[kind];
			if (!target) {
				target = ensureBldgGroup(kinds, kindOrder, kind);
			}
			if (!target) {
				return;
			}
			var props = feat.properties || {};
			var withErgo = ergoCtx && ergoCtx.yardErgoAtUrl && ergoCtx.cityId && isBldgKind(kind);
			var gj = L.geoJSON(feat, {
				style: function (f) {
					var g = f.geometry && f.geometry.type;
					return osmStyle(kind, g);
				},
				pointToLayer: function (f, latlng) {
					return L.circleMarker(latlng, osmPointStyle(kind));
				},
				onEachFeature: function (f, layer) {
					layer.bindPopup(osmPopup(props, withErgo, ergoCtx));
					if (withErgo) {
						bindBuildingErgoPopup(layer, props, ergoCtx);
					}
				}
			});
			gj.eachLayer(function (layer) {
				target.addLayer(layer);
			});
			if (seenKeys && featureKey) {
				seenKeys[featureKey] = true;
			}
			added++;
		});
		return added;
	}

	function buildOverlaysObject(m, Ltxt, labels, kinds, kindOrder, yardsGroup, centerGroup, voronoiGroup, territoryDebugGroup) {
		var overlays = {};
		overlays[Ltxt.layerCenter || 'Center'] = centerGroup;
		kindOrder.forEach(function (k) {
			var g = kinds[k];
			if (!g || g.getLayers().length === 0) {
				return;
			}
			if (k !== 'path') {
				g.addTo(m);
			}
			var title = isBldgKind(k) ? labels[k] || k : null;
			if (!title) {
				if (k === 'landuse_green') title = Ltxt.layerGreen;
				else if (k === 'playground') title = Ltxt.layerPlay;
				else if (k === 'bench') title = Ltxt.layerBench;
				else if (k === 'light') title = Ltxt.layerLight;
				else if (k === 'waste_basket') title = Ltxt.layerBin;
				else if (k === 'path') title = Ltxt.layerPath;
			}
			overlays[title || k] = g;
		});
		if (yardsGroup.getLayers().length > 0) {
			var hideYardsUnderPreview =
				voronoiGroup &&
				voronoiGroup.getLayers &&
				voronoiGroup.getLayers().length > 0;
			if (!hideYardsUnderPreview) {
				yardsGroup.addTo(m);
			}
			overlays[Ltxt.layerYards || 'Yards'] = yardsGroup;
		}
		if (voronoiGroup && voronoiGroup.getLayers().length > 0) {
			voronoiGroup.addTo(m);
			overlays[Ltxt.voronoiLayer || 'Voronoi preview'] = voronoiGroup;
		}
		if (territoryDebugGroup && territoryDebugGroup.getLayers().length > 0) {
			overlays[Ltxt.territoryDebugLayer || 'Territory debug'] = territoryDebugGroup;
		}
		return overlays;
	}

	function makeScanProgressId() {
		if (window.crypto && window.crypto.getRandomValues) {
			var a = new Uint8Array(16);
			window.crypto.getRandomValues(a);
			return Array.from(a, function (b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
		}
		var s = '';
		for (var i = 0; i < 32; i++) {
			s += (Math.floor(Math.random() * 16)).toString(16);
		}
		return s;
	}

	function ensureScanProgressRow(mapEl) {
		var prev = mapEl.previousElementSibling;
		if (prev && prev.classList && prev.classList.contains('wscosm-scan-progress-row')) {
			return prev;
		}
		var row = document.createElement('div');
		row.className = 'wscosm-scan-progress-row';
		row.setAttribute('hidden', 'hidden');
		row.innerHTML =
			'<div class="wscosm-scan-progress__label"></div>' +
			'<div class="wscosm-scan-progress"><div class="wscosm-scan-progress__bar"></div></div>' +
			'<div class="wscosm-scan-progress__detail"></div>';
		mapEl.parentNode.insertBefore(row, mapEl);
		return row;
	}

	function scanViewportUrl(featuresUrl, bounds, progressId) {
		var u = new URL(featuresUrl, window.location.href);
		u.searchParams.set('south', String(bounds.getSouth()));
		u.searchParams.set('west', String(bounds.getWest()));
		u.searchParams.set('north', String(bounds.getNorth()));
		u.searchParams.set('east', String(bounds.getEast()));
		u.searchParams.set('refresh', '1');
		u.searchParams.set('source', 'live');
		if (progressId) {
			u.searchParams.set('progress_id', progressId);
		}
		return u.toString();
	}

	function localViewportUrl(featuresUrl, bounds) {
		var u = new URL(featuresUrl, window.location.href);
		u.searchParams.set('south', String(bounds.getSouth()));
		u.searchParams.set('west', String(bounds.getWest()));
		u.searchParams.set('north', String(bounds.getNorth()));
		u.searchParams.set('east', String(bounds.getEast()));
		u.searchParams.set('source', 'local');
		u.searchParams.delete('refresh');
		u.searchParams.delete('progress_id');
		return u.toString();
	}

	function walkGeometryCoords(coords, cb) {
		if (!Array.isArray(coords)) return;
		if (
			coords.length >= 2 &&
			typeof coords[0] === 'number' &&
			typeof coords[1] === 'number' &&
			!Array.isArray(coords[0])
		) {
			cb(coords[0], coords[1]);
			return;
		}
		coords.forEach(function (c) {
			walkGeometryCoords(c, cb);
		});
	}

	function geometryBounds(geom) {
		if (!geom || !geom.coordinates) return null;
		var b = { w: Infinity, s: Infinity, e: -Infinity, n: -Infinity };
		walkGeometryCoords(geom.coordinates, function (lon, lat) {
			if (!isFinite(lon) || !isFinite(lat)) return;
			b.w = Math.min(b.w, lon);
			b.e = Math.max(b.e, lon);
			b.s = Math.min(b.s, lat);
			b.n = Math.max(b.n, lat);
		});
		return b.w === Infinity || b.s === Infinity || b.e === -Infinity || b.n === -Infinity ? null : b;
	}

	function buildingSeedsFromStore(featureStore) {
		var out = [];
		Object.keys(featureStore || {}).forEach(function (key) {
			var feat = featureStore[key];
			var props = (feat && feat.properties) || {};
			var kind = props.wscosm_kind || '';
			if (!isBldgKind(kind)) return;
			if (kind === 'bldg_part') return;
			var bounds = geometryBounds(feat.geometry);
			if (!bounds) return;
			var lon = (bounds.w + bounds.e) / 2;
			var lat = (bounds.s + bounds.n) / 2;
			if (!isFinite(lon) || !isFinite(lat)) return;
			out.push({
				key: key,
				x: lon,
				y: lat,
				lon: lon,
				lat: lat,
				bounds: bounds,
				props: props
			});
		});
		out.sort(function (a, b) {
			return a.key < b.key ? -1 : a.key > b.key ? 1 : 0;
		});
		return out;
	}

	function expandBoundsMeters(bounds, lat, bufferM) {
		var latPad = bufferM / 111320;
		var cos = Math.cos(((lat || (bounds.s + bounds.n) / 2) * Math.PI) / 180);
		cos = Math.max(0.15, Math.min(1, Math.abs(cos)));
		var lonPad = bufferM / (111320 * cos);
		return {
			w: bounds.w - lonPad,
			s: bounds.s - latPad,
			e: bounds.e + lonPad,
			n: bounds.n + latPad
		};
	}

	function expandedSeedBounds(seeds, cityLat, bufferM) {
		if (!seeds.length) return null;
		var b = { w: Infinity, s: Infinity, e: -Infinity, n: -Infinity };
		seeds.forEach(function (seed) {
			b.w = Math.min(b.w, seed.bounds.w);
			b.e = Math.max(b.e, seed.bounds.e);
			b.s = Math.min(b.s, seed.bounds.s);
			b.n = Math.max(b.n, seed.bounds.n);
		});
		if (b.w === Infinity) return null;
		return expandBoundsMeters(b, cityLat, bufferM);
	}

	function closeRing(coords) {
		if (!coords.length) return coords;
		var first = coords[0];
		var last = coords[coords.length - 1];
		if (first[0] !== last[0] || first[1] !== last[1]) {
			coords.push([first[0], first[1]]);
		}
		return coords;
	}

	function polygonArea(coords) {
		var sum = 0;
		for (var i = 0; i < coords.length - 1; i++) {
			sum += coords[i][0] * coords[i + 1][1] - coords[i + 1][0] * coords[i][1];
		}
		return Math.abs(sum / 2);
	}

	function clipPolygonByBisector(poly, aSeed, bSeed) {
		var a = 2 * (bSeed.x - aSeed.x);
		var b = 2 * (bSeed.y - aSeed.y);
		var c = bSeed.x * bSeed.x + bSeed.y * bSeed.y - aSeed.x * aSeed.x - aSeed.y * aSeed.y;
		var eps = 1e-12;
		var out = [];
		if (!poly.length) return out;
		function inside(p) {
			return a * p.x + b * p.y <= c + eps;
		}
		function intersect(p1, p2) {
			var dx = p2.x - p1.x;
			var dy = p2.y - p1.y;
			var den = a * dx + b * dy;
			if (Math.abs(den) < eps) return { x: p2.x, y: p2.y };
			var t = (c - a * p1.x - b * p1.y) / den;
			t = Math.max(0, Math.min(1, t));
			return { x: p1.x + dx * t, y: p1.y + dy * t };
		}
		for (var i = 0; i < poly.length; i++) {
			var cur = poly[i];
			var prev = poly[(i + poly.length - 1) % poly.length];
			var curIn = inside(cur);
			var prevIn = inside(prev);
			if (curIn) {
				if (!prevIn) out.push(intersect(prev, cur));
				out.push(cur);
			} else if (prevIn) {
				out.push(intersect(prev, cur));
			}
		}
		return out;
	}

	function clipPolygonToRect(poly, rect) {
		function clipEdge(input, inside, intersect) {
			var out = [];
			if (!input.length) return out;
			for (var i = 0; i < input.length; i++) {
				var cur = input[i];
				var prev = input[(i + input.length - 1) % input.length];
				var curIn = inside(cur);
				var prevIn = inside(prev);
				if (curIn) {
					if (!prevIn) out.push(intersect(prev, cur));
					out.push(cur);
				} else if (prevIn) {
					out.push(intersect(prev, cur));
				}
			}
			return out;
		}
		var eps = 1e-12;
		var out = poly;
		out = clipEdge(
			out,
			function (p) { return p.x >= rect.w - eps; },
			function (a, b) {
				var t = (rect.w - a.x) / (b.x - a.x || eps);
				return { x: rect.w, y: a.y + (b.y - a.y) * t };
			}
		);
		out = clipEdge(
			out,
			function (p) { return p.x <= rect.e + eps; },
			function (a, b) {
				var t = (rect.e - a.x) / (b.x - a.x || eps);
				return { x: rect.e, y: a.y + (b.y - a.y) * t };
			}
		);
		out = clipEdge(
			out,
			function (p) { return p.y >= rect.s - eps; },
			function (a, b) {
				var t = (rect.s - a.y) / (b.y - a.y || eps);
				return { x: a.x + (b.x - a.x) * t, y: rect.s };
			}
		);
		out = clipEdge(
			out,
			function (p) { return p.y <= rect.n + eps; },
			function (a, b) {
				var t = (rect.n - a.y) / (b.y - a.y || eps);
				return { x: a.x + (b.x - a.x) * t, y: rect.n };
			}
		);
		return out;
	}

	function propTag(props, name) {
		return (props && (props['tag_' + name] || props[name])) || '';
	}

	function projectedPoint(lon, lat, originLat) {
		var cos = Math.cos((originLat * Math.PI) / 180);
		cos = Math.max(0.15, Math.min(1, Math.abs(cos)));
		return { x: lon * 111320 * cos, y: lat * 111320 };
	}

	function lonLatPoint(x, y, originLat) {
		var cos = Math.cos((originLat * Math.PI) / 180);
		cos = Math.max(0.15, Math.min(1, Math.abs(cos)));
		return [Number((x / (111320 * cos)).toFixed(7)), Number((y / 111320).toFixed(7))];
	}

	function distanceToSegmentMeters(p, a, b) {
		var dx = b.x - a.x;
		var dy = b.y - a.y;
		var len2 = dx * dx + dy * dy;
		if (!len2) {
			dx = p.x - a.x;
			dy = p.y - a.y;
			return Math.sqrt(dx * dx + dy * dy);
		}
		var t = ((p.x - a.x) * dx + (p.y - a.y) * dy) / len2;
		t = Math.max(0, Math.min(1, t));
		var qx = a.x + t * dx;
		var qy = a.y + t * dy;
		dx = p.x - qx;
		dy = p.y - qy;
		return Math.sqrt(dx * dx + dy * dy);
	}

	function pointInRingProjected(p, ring) {
		var inside = false;
		for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
			var a = ring[i];
			var b = ring[j];
			var crosses = a.y > p.y !== b.y > p.y;
			if (crosses && p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y || 1e-12) + a.x) {
				inside = !inside;
			}
		}
		return inside;
	}

	function pointInProjectedPolygons(p, polygons) {
		for (var i = 0; i < polygons.length; i++) {
			var poly = polygons[i];
			if (!poly.length || !pointInRingProjected(p, poly[0])) continue;
			var inHole = false;
			for (var h = 1; h < poly.length; h++) {
				if (pointInRingProjected(p, poly[h])) {
					inHole = true;
					break;
				}
			}
			if (!inHole) return true;
		}
		return false;
	}

	function forEachCoordPair(coords, cb) {
		if (!Array.isArray(coords)) return;
		if (coords.length >= 2 && typeof coords[0] === 'number' && typeof coords[1] === 'number') {
			cb(coords[0], coords[1]);
			return;
		}
		coords.forEach(function (c) {
			forEachCoordPair(c, cb);
		});
	}

	function projectedLineSegments(geom, originLat) {
		var segs = [];
		function addLine(line) {
			if (!Array.isArray(line)) return;
			for (var i = 1; i < line.length; i++) {
				var a = line[i - 1];
				var b = line[i];
				if (!Array.isArray(a) || !Array.isArray(b)) continue;
				segs.push({
					a: projectedPoint(a[0], a[1], originLat),
					b: projectedPoint(b[0], b[1], originLat)
				});
			}
		}
		if (!geom) return segs;
		if (geom.type === 'LineString') {
			addLine(geom.coordinates);
		} else if (geom.type === 'MultiLineString' || geom.type === 'Polygon') {
			(geom.coordinates || []).forEach(addLine);
		} else if (geom.type === 'MultiPolygon') {
			(geom.coordinates || []).forEach(function (poly) {
				(poly || []).forEach(addLine);
			});
		}
		return segs;
	}

	function projectedPolygons(geom, originLat) {
		function ringToProjected(ring) {
			return (ring || []).map(function (c) {
				return projectedPoint(c[0], c[1], originLat);
			});
		}
		if (!geom || !geom.coordinates) return [];
		if (geom.type === 'Polygon') {
			return [(geom.coordinates || []).map(ringToProjected)];
		}
		if (geom.type === 'MultiPolygon') {
			return (geom.coordinates || []).map(function (poly) {
				return (poly || []).map(ringToProjected);
			});
		}
		return [];
	}

	function featureCenterPoint(feature, originLat) {
		var b = geometryBounds(feature.geometry);
		if (!b) return null;
		return {
			lon: (b.w + b.e) / 2,
			lat: (b.s + b.n) / 2,
			p: projectedPoint((b.w + b.e) / 2, (b.s + b.n) / 2, originLat),
			bounds: b
		};
	}

	function buildingSamples(feature, originLat) {
		var samples = [];
		var center = featureCenterPoint(feature, originLat);
		if (center) samples.push(center.p);
		var every = 1;
		var count = 0;
		forEachCoordPair(feature.geometry && feature.geometry.coordinates, function () {
			count++;
		});
		if (count > 24) every = Math.ceil(count / 24);
		var idx = 0;
		forEachCoordPair(feature.geometry && feature.geometry.coordinates, function (lon, lat) {
			if (idx % every === 0) {
				samples.push(projectedPoint(lon, lat, originLat));
			}
			idx++;
		});
		return samples;
	}

	function distanceToBuildingMeters(p, building) {
		if (pointInProjectedPolygons(p, building.polygons)) return 0;
		var best = Infinity;
		for (var i = 0; i < building.segments.length; i++) {
			best = Math.min(best, distanceToSegmentMeters(p, building.segments[i].a, building.segments[i].b));
		}
		return best;
	}

	function roadBufferMeters(props, optKind) {
		var buffers = TERRITORY_CONFIG.roadBuffers || {};
		var kind = optKind !== undefined ? optKind : String((props || {}).wscosm_kind || '');
		var hw = String(propTag(props, 'highway') || '').toLowerCase();
		if (hw) {
			if (!TERRITORY_CONFIG.useFootwaysAsBarriers && /^(footway|path|pedestrian|steps|cycleway)$/.test(hw)) {
				return 0;
			}
			return buffers[hw] !== undefined ? buffers[hw] : buffers.default;
		}
		if (kind === 'road') {
			var t = buffers.tertiary !== undefined ? buffers.tertiary : 10;
			var d = buffers.default !== undefined ? buffers.default : 8;
			return Math.max(t, d);
		}
		if (kind === 'path') {
			if (!TERRITORY_CONFIG.useFootwaysAsBarriers) {
				return 0;
			}
			if (buffers.path !== undefined) {
				return buffers.path;
			}
			return buffers.service !== undefined ? buffers.service : 4;
		}
		return 0;
	}

	function scanOsmObjects(featureStore, bounds) {
		var originLat = (bounds.getSouth() + bounds.getNorth()) / 2;
		var inputs = {
			originLat: originLat,
			buildings: [],
			roads: [],
			railways: [],
			waters: [],
			barriers: [],
			obstacles: [],
			sourceFeatures: []
		};
		Object.keys(featureStore || {}).forEach(function (key) {
			var feat = featureStore[key];
			var props = (feat && feat.properties) || {};
			var kind = props.wscosm_kind || '';
			var geom = feat && feat.geometry;
			if (!geom) return;
			inputs.sourceFeatures.push(feat);
			if (isBldgKind(kind) && kind !== 'bldg_part') {
				var center = featureCenterPoint(feat, originLat);
				var polygons = projectedPolygons(geom, originLat);
				if (!center || !polygons.length) return;
				inputs.buildings.push({
					key: key,
					feature: feat,
					props: props,
					center: center,
					polygons: polygons,
					segments: projectedLineSegments(geom, originLat),
					samples: buildingSamples(feat, originLat),
					quarterIds: {}
				});
				return;
			}
			var hwBuffer = roadBufferMeters(props, kind);
			if (hwBuffer > 0) {
				inputs.roads.push({ feature: feat, buffer: hwBuffer, segments: projectedLineSegments(geom, originLat) });
				return;
			}
			if (kind === 'railway' || propTag(props, 'railway')) {
				inputs.railways.push({ feature: feat, buffer: 10, segments: projectedLineSegments(geom, originLat) });
				return;
			}
			if (kind === 'water' || propTag(props, 'natural') === 'water' || propTag(props, 'waterway')) {
				inputs.waters.push({
					feature: feat,
					buffer: geom.type === 'LineString' || geom.type === 'MultiLineString' ? 8 : 0,
					segments: projectedLineSegments(geom, originLat),
					polygons: projectedPolygons(geom, originLat)
				});
				return;
			}
			if (kind === 'barrier' || /^(fence|wall|retaining_wall|hedge)$/.test(String(propTag(props, 'barrier')))) {
				inputs.barriers.push({ feature: feat, buffer: 3, segments: projectedLineSegments(geom, originLat) });
				return;
			}
			if (
				kind === 'parking' ||
				kind === 'landuse_industrial' ||
				kind === 'landuse_railway' ||
				kind === 'landuse_construction' ||
				kind === 'landuse_commercial' ||
				kind === 'landuse_retail' ||
				kind === 'restricted_area'
			) {
				inputs.obstacles.push({
					feature: feat,
					polygons: projectedPolygons(geom, originLat),
					segments: projectedLineSegments(geom, originLat)
				});
			}
		});
		return inputs;
	}

	function buildObstacleGeometry(osmObjects) {
		var inputs = osmObjects || {};
		var obstacleItems = [];
		(inputs.buildings || []).forEach(function (item) {
			obstacleItems.push({ type: 'building', feature: item.feature, polygons: item.polygons, segments: item.segments });
		});
		(inputs.roads || []).forEach(function (item) {
			obstacleItems.push({ type: 'road', feature: item.feature, buffer: item.buffer, segments: item.segments });
		});
		(inputs.railways || []).forEach(function (item) {
			obstacleItems.push({ type: 'railway', feature: item.feature, buffer: item.buffer, segments: item.segments });
		});
		(inputs.waters || []).forEach(function (item) {
			obstacleItems.push({
				type: 'water',
				feature: item.feature,
				buffer: item.buffer || 0,
				polygons: item.polygons,
				segments: item.segments
			});
		});
		(inputs.barriers || []).forEach(function (item) {
			obstacleItems.push({ type: 'barrier', feature: item.feature, buffer: item.buffer, segments: item.segments });
		});
		(inputs.obstacles || []).forEach(function (item) {
			obstacleItems.push({
				type: 'area_obstacle',
				feature: item.feature,
				polygons: item.polygons,
				segments: item.segments
			});
		});
		return {
			inputs: inputs,
			items: obstacleItems,
			blockers: {
				roads: inputs.roads || [],
				railways: inputs.railways || [],
				waters: inputs.waters || [],
				barriers: inputs.barriers || [],
				areas: inputs.obstacles || []
			}
		};
	}

	function buildWorkingArea(mapBounds) {
		return mapBounds;
	}

	function buildFreeArea(workingArea, obstacles) {
		return {
			bounds: workingArea,
			obstacles: obstacles,
			inputs: obstacles.inputs
		};
	}

	function minDistanceToBufferedLines(p, items) {
		var best = Infinity;
		for (var i = 0; i < items.length; i++) {
			var item = items[i];
			for (var s = 0; s < item.segments.length; s++) {
				best = Math.min(best, distanceToSegmentMeters(p, item.segments[s].a, item.segments[s].b) - item.buffer);
			}
		}
		return best;
	}

	function isBlockedTerritoryPoint(p, inputs) {
		if (minDistanceToBufferedLines(p, inputs.roads) <= 0) return true;
		if (minDistanceToBufferedLines(p, inputs.railways) <= 0) return true;
		if (minDistanceToBufferedLines(p, inputs.barriers) <= 0) return true;
		for (var w = 0; w < inputs.waters.length; w++) {
			if (inputs.waters[w].polygons.length && pointInProjectedPolygons(p, inputs.waters[w].polygons)) return true;
			if (inputs.waters[w].buffer && minDistanceToBufferedLines(p, [inputs.waters[w]]) <= 0) return true;
		}
		for (var o = 0; o < inputs.obstacles.length; o++) {
			if (pointInProjectedPolygons(p, inputs.obstacles[o].polygons)) return true;
		}
		for (var b = 0; b < inputs.buildings.length; b++) {
			if (pointInProjectedPolygons(p, inputs.buildings[b].polygons)) return true;
		}
		return false;
	}

	function orientation(a, b, c) {
		var v = (b.y - a.y) * (c.x - b.x) - (b.x - a.x) * (c.y - b.y);
		if (Math.abs(v) < 1e-9) return 0;
		return v > 0 ? 1 : 2;
	}

	function onSegment(a, b, c) {
		return (
			Math.min(a.x, c.x) - 1e-9 <= b.x &&
			b.x <= Math.max(a.x, c.x) + 1e-9 &&
			Math.min(a.y, c.y) - 1e-9 <= b.y &&
			b.y <= Math.max(a.y, c.y) + 1e-9
		);
	}

	function segmentsIntersect(a, b, c, d) {
		var o1 = orientation(a, b, c);
		var o2 = orientation(a, b, d);
		var o3 = orientation(c, d, a);
		var o4 = orientation(c, d, b);
		if (o1 !== o2 && o3 !== o4) return true;
		if (o1 === 0 && onSegment(a, c, b)) return true;
		if (o2 === 0 && onSegment(a, d, b)) return true;
		if (o3 === 0 && onSegment(c, a, d)) return true;
		if (o4 === 0 && onSegment(c, b, d)) return true;
		return false;
	}

	function distanceBetweenSegmentsMeters(a, b, c, d) {
		if (segmentsIntersect(a, b, c, d)) return 0;
		return Math.min(
			distanceToSegmentMeters(a, c, d),
			distanceToSegmentMeters(b, c, d),
			distanceToSegmentMeters(c, a, b),
			distanceToSegmentMeters(d, a, b)
		);
	}

	function lineHitsBufferedSegments(a, b, items) {
		for (var i = 0; i < items.length; i++) {
			var item = items[i];
			var buffer = item.buffer || 0;
			for (var s = 0; s < (item.segments || []).length; s++) {
				if (distanceBetweenSegmentsMeters(a, b, item.segments[s].a, item.segments[s].b) <= buffer) {
					return true;
				}
			}
		}
		return false;
	}

	function lineHitsPolygons(a, b, items) {
		for (var i = 0; i < items.length; i++) {
			var item = items[i];
			var polygons = item.polygons || [];
			for (var p = 0; p < polygons.length; p++) {
				var poly = polygons[p];
				if (!poly.length) continue;
				if (pointInProjectedPolygons(a, [poly])) return true;
				for (var r = 0; r < poly.length; r++) {
					var ring = poly[r];
					for (var c = 1; c < ring.length; c++) {
						if (segmentsIntersect(a, b, ring[c - 1], ring[c])) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	function findNearestBuilding(point, buildings) {
		var best = null;
		var bestDist = Infinity;
		(buildings || []).forEach(function (building) {
			var d = distanceToBuildingMeters(point, building);
			if (d < bestDist) {
				bestDist = d;
				best = building;
			}
		});
		return best ? { building: best, distance: bestDist } : null;
	}

	function isPathBlocked(point, building, obstacles) {
		if (!TERRITORY_CONFIG.useLineOfSightCheck || !building || !building.center) return false;
		var target = building.center.p;
		var blockers = (obstacles && obstacles.blockers) || {};
		if (lineHitsBufferedSegments(point, target, blockers.roads || [])) return true;
		if (lineHitsBufferedSegments(point, target, blockers.railways || [])) return true;
		if (lineHitsBufferedSegments(point, target, blockers.barriers || [])) return true;
		if (lineHitsBufferedSegments(point, target, blockers.waters || [])) return true;
		if (lineHitsPolygons(point, target, blockers.waters || [])) return true;
		if (lineHitsPolygons(point, target, blockers.areas || [])) return true;
		return false;
	}

	function generateGrid(freeArea, cellSizeMeters) {
		var inputs = freeArea.inputs;
		var bounds = freeArea.bounds;
		var sw = projectedPoint(bounds.getWest(), bounds.getSouth(), inputs.originLat);
		var ne = projectedPoint(bounds.getEast(), bounds.getNorth(), inputs.originLat);
		var width = Math.max(1, ne.x - sw.x);
		var height = Math.max(1, ne.y - sw.y);
		var requestedCell = Math.max(1, cellSizeMeters || TERRITORY_CONFIG.cellSizeMeters);
		var requestedCells = Math.ceil(width / requestedCell) * Math.ceil(height / requestedCell);
		var cell = Math.max(
			requestedCell,
			Math.sqrt((width * height) / TERRITORY_CONFIG.maxGridCells)
		);
		if (requestedCells > TERRITORY_CONFIG.maxGridCells) {
			console.warn('[RasterTerritory] Too many grid cells. Increase cell size or zoom in.');
		}
		var nx = Math.max(1, Math.ceil(width / cell));
		var ny = Math.max(1, Math.ceil(height / cell));
		var free = new Uint8Array(nx * ny);
		var comps = new Int32Array(nx * ny);
		for (var c = 0; c < comps.length; c++) comps[c] = -1;
		function idx(x, y) {
			return y * nx + x;
		}
		function center(ix, iy) {
			return { x: sw.x + (ix + 0.5) * cell, y: sw.y + (iy + 0.5) * cell };
		}
		for (var iy = 0; iy < ny; iy++) {
			for (var ix = 0; ix < nx; ix++) {
				free[idx(ix, iy)] = isBlockedTerritoryPoint(center(ix, iy), inputs) ? 0 : 1;
			}
		}
		var quarters = [];
		var qid = 0;
		var q = [];
		for (iy = 0; iy < ny; iy++) {
			for (ix = 0; ix < nx; ix++) {
				var start = idx(ix, iy);
				if (!free[start] || comps[start] !== -1) continue;
				var cells = [];
				q.length = 0;
				q.push([ix, iy]);
				comps[start] = qid;
				while (q.length) {
					var cur = q.pop();
					var cx = cur[0];
					var cy = cur[1];
					cells.push(cur);
					[[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(function (d) {
						var nx2 = cx + d[0];
						var ny2 = cy + d[1];
						if (nx2 < 0 || nx2 >= nx || ny2 < 0 || ny2 >= ny) return;
						var ni = idx(nx2, ny2);
						if (free[ni] && comps[ni] === -1) {
							comps[ni] = qid;
							q.push([nx2, ny2]);
						}
					});
				}
				quarters.push({ id: 'q' + qid, index: qid, cells: cells });
				qid++;
			}
		}
		var freeCellCount = 0;
		for (var fi = 0; fi < free.length; fi++) {
			freeCellCount += free[fi] ? 1 : 0;
		}
		return {
			minX: sw.x,
			minY: sw.y,
			cell: cell,
			requestedCell: requestedCell,
			nx: nx,
			ny: ny,
			totalCells: nx * ny,
			freeCells: freeCellCount,
			free: free,
			comps: comps,
			quarters: quarters,
			idx: idx,
			center: center
		};
	}

	function assignBuildingsToQuarters(inputs, grid) {
		var searchCells = Math.max(2, Math.ceil(TERRITORY_CONFIG.maxDistanceMeters / grid.cell));
		inputs.buildings.forEach(function (building) {
			building.quarterIds = {};
			building.samples.forEach(function (sample) {
				var ix = Math.floor((sample.x - grid.minX) / grid.cell);
				var iy = Math.floor((sample.y - grid.minY) / grid.cell);
				for (var dy = -searchCells; dy <= searchCells; dy++) {
					for (var dx = -searchCells; dx <= searchCells; dx++) {
						var x = ix + dx;
						var y = iy + dy;
						if (x < 0 || x >= grid.nx || y < 0 || y >= grid.ny) continue;
						var gi = grid.idx(x, y);
						var qid = grid.comps[gi];
						if (qid < 0) continue;
						var d = distanceToBuildingMeters(grid.center(x, y), building);
						if (d <= TERRITORY_CONFIG.maxDistanceMeters) {
							building.quarterIds[qid] = true;
						}
					}
				}
			});
		});
	}

	function assignGridCellsToBuildings(gridCells, buildings, obstacles, config, progress) {
		var inputs = obstacles.inputs;
		assignBuildingsToQuarters(inputs, gridCells);
		var byQuarter = {};
		(buildings || []).forEach(function (building) {
			Object.keys(building.quarterIds || {}).forEach(function (qid) {
				if (!byQuarter[qid]) byQuarter[qid] = [];
				byQuarter[qid].push(building);
			});
		});
		var assignments = {
			byBuilding: {},
			assignedCells: [],
			unassignedCells: [],
			gridCells: [],
			totalFreeCells: 0
		};
		var total = gridCells.quarters.length || 1;
		gridCells.quarters.forEach(function (quarter, qi) {
			var candidates = byQuarter[quarter.index] || [];
			quarter.cells.forEach(function (cell) {
				assignments.gridCells.push(cell);
				assignments.totalFreeCells++;
				var p = gridCells.center(cell[0], cell[1]);
				if (!candidates.length) {
					assignments.unassignedCells.push(cell);
					return;
				}
				var nearest = findNearestBuilding(p, candidates);
				if (!nearest || nearest.distance > config.maxDistanceMeters) {
					assignments.unassignedCells.push(cell);
					return;
				}
				if (isPathBlocked(p, nearest.building, obstacles)) {
					assignments.unassignedCells.push(cell);
					return;
				}
				var key = nearest.building.key + '|' + quarter.id;
				if (!assignments.byBuilding[key]) {
					assignments.byBuilding[key] = { building: nearest.building, quarter: quarter, cells: [] };
				}
				assignments.byBuilding[key].cells.push(cell);
				assignments.assignedCells.push(cell);
			});
			if (progress) progress(qi + 1, total);
		});
		return assignments;
	}

	function ringAreaProjected(ring) {
		var sum = 0;
		for (var i = 0; i < ring.length - 1; i++) {
			sum += ring[i].x * ring[i + 1].y - ring[i + 1].x * ring[i].y;
		}
		return sum / 2;
	}

	function traceCellRings(cells, grid) {
		var set = {};
		cells.forEach(function (c) {
			set[c[0] + ',' + c[1]] = true;
		});
		var edges = {};
		function key(p) {
			return Math.round(p.x * 1000) + ',' + Math.round(p.y * 1000);
		}
		function addEdge(a, b) {
			var k = key(a);
			if (!edges[k]) edges[k] = [];
			edges[k].push({ a: a, b: b, used: false });
		}
		cells.forEach(function (c) {
			var ix = c[0];
			var iy = c[1];
			var x0 = grid.minX + ix * grid.cell;
			var y0 = grid.minY + iy * grid.cell;
			var x1 = x0 + grid.cell;
			var y1 = y0 + grid.cell;
			if (!set[ix + ',' + (iy + 1)]) addEdge({ x: x0, y: y1 }, { x: x1, y: y1 });
			if (!set[(ix + 1) + ',' + iy]) addEdge({ x: x1, y: y1 }, { x: x1, y: y0 });
			if (!set[ix + ',' + (iy - 1)]) addEdge({ x: x1, y: y0 }, { x: x0, y: y0 });
			if (!set[(ix - 1) + ',' + iy]) addEdge({ x: x0, y: y0 }, { x: x0, y: y1 });
		});
		var rings = [];
		Object.keys(edges).forEach(function (startKey) {
			(edges[startKey] || []).forEach(function (edge) {
				if (edge.used) return;
				var ring = [edge.a];
				var cur = edge;
				var guard = 0;
				while (cur && !cur.used && guard++ < 100000) {
					cur.used = true;
					ring.push(cur.b);
					var nextList = edges[key(cur.b)] || [];
					cur = null;
					for (var i = 0; i < nextList.length; i++) {
						if (!nextList[i].used) {
							cur = nextList[i];
							break;
						}
					}
					if (ring.length > 3 && key(ring[0]) === key(ring[ring.length - 1])) break;
				}
				if (ring.length >= 4 && key(ring[0]) === key(ring[ring.length - 1])) {
					rings.push(ring);
				}
			});
		});
		return rings;
	}

	function cellsToPolygonGeometry(cells, grid, originLat) {
		var rings = traceCellRings(cells, grid);
		if (!rings.length) return null;
		rings.sort(function (a, b) {
			return Math.abs(ringAreaProjected(b)) - Math.abs(ringAreaProjected(a));
		});
		var outer = rings[0];
		var outerArea = Math.abs(ringAreaProjected(outer));
		if (outerArea < TERRITORY_CONFIG.minAreaM2) return null;
		var coords = [
			closeRing(
				outer.map(function (p) {
					return lonLatPoint(p.x, p.y, originLat);
				})
			)
		];
		for (var i = 1; i < rings.length; i++) {
			var r = rings[i];
			if (Math.abs(ringAreaProjected(r)) < TERRITORY_CONFIG.minAreaM2) continue;
			var mid = r[Math.floor(r.length / 2)];
			if (!pointInRingProjected(mid, outer)) continue;
			coords.push(
				closeRing(
					r.map(function (p) {
						return lonLatPoint(p.x, p.y, originLat);
					})
				)
			);
		}
		return { type: 'Polygon', coordinates: simplifyPolygonCoordinates(coords) };
	}

	function simplifyPolygonCoordinates(coords) {
		return (coords || []).map(function (ring) {
			if (!ring || ring.length <= 4) return ring;
			var simplified = [];
			for (var i = 0; i < ring.length - 1; i++) {
				var prev = ring[(i + ring.length - 2) % (ring.length - 1)];
				var cur = ring[i];
				var next = ring[(i + 1) % (ring.length - 1)];
				var area2 =
					(prev[0] - cur[0]) * (next[1] - cur[1]) -
					(prev[1] - cur[1]) * (next[0] - cur[0]);
				if (Math.abs(area2) > 1e-14) {
					simplified.push(cur);
				}
			}
			if (simplified.length < 3) return ring;
			return closeRing(simplified);
		});
	}

	function splitCellComponents(cells) {
		var set = {};
		var visited = {};
		cells.forEach(function (cell) {
			set[cell[0] + ',' + cell[1]] = cell;
		});
		var components = [];
		cells.forEach(function (cell) {
			var startKey = cell[0] + ',' + cell[1];
			if (visited[startKey]) return;
			var queue = [cell];
			var component = [];
			visited[startKey] = true;
			while (queue.length) {
				var cur = queue.pop();
				component.push(cur);
				[[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(function (d) {
					var key = cur[0] + d[0] + ',' + (cur[1] + d[1]);
					if (!set[key] || visited[key]) return;
					visited[key] = true;
					queue.push(set[key]);
				});
			}
			components.push(component);
		});
		return components;
	}

	function mergeCellsByBuilding(assignments, grid, originLat) {
		var features = [];
		Object.keys(assignments.byBuilding || {}).forEach(function (key) {
			var bucket = assignments.byBuilding[key];
			splitCellComponents(bucket.cells).forEach(function (componentCells, componentIndex) {
				var geom = cellsToPolygonGeometry(componentCells, grid, originLat);
				if (!geom) return;
				var areaM2 = Math.round(componentCells.length * grid.cell * grid.cell * 10) / 10;
				if (areaM2 < TERRITORY_CONFIG.minAreaM2) return;
				var props = bucket.building.props || {};
				var osmId = props.wscosm_osm_id || '';
				var id = 'territory_' + simpleHash(key + ':' + componentIndex + ':' + areaM2);
				features.push({
					type: 'Feature',
					properties: {
						id: id,
						object_key: id,
						building_id: bucket.building.key,
						osm_id: osmId,
						wscosm_kind: props.wscosm_kind || 'bldg_other',
						wscosm_osm_el_type: props.wscosm_osm_el_type || '',
						wscosm_osm_id: props.wscosm_osm_id || 0,
						area_m2: areaM2,
						quarter_id: bucket.quarter.id,
						method: TERRITORY_CONFIG.method,
						cell_size_m: grid.cell,
						max_distance_m: TERRITORY_CONFIG.maxDistanceMeters,
						created_at: new Date().toISOString(),
						name: props.name || '',
						title: props.name || bucket.building.key,
						center: { lat: bucket.building.center.lat, lng: bucket.building.center.lon },
						lat: bucket.building.center.lat,
						lng: bucket.building.center.lon
					},
					geometry: geom
				});
			});
		});
		return features;
	}

	function territoryFeatureBbox(feature) {
		var b = geometryBounds(feature.geometry);
		return b || { w: 0, s: 0, e: 0, n: 0 };
	}

	function bboxesOverlap(a, b) {
		return a.w < b.e && a.e > b.w && a.s < b.n && a.n > b.s;
	}

	function validateTerritories(territories) {
		var valid = [];
		var overlapCount = 0;
		var invalidCount = 0;
		for (var i = 0; i < territories.length; i++) {
			var feat = territories[i];
			var props = feat.properties || {};
			if (!props.building_id || !feat.geometry || !/^(Polygon|MultiPolygon)$/.test(feat.geometry.type || '')) {
				invalidCount++;
				continue;
			}
			if ((props.area_m2 || 0) < TERRITORY_CONFIG.minAreaM2) {
				invalidCount++;
				continue;
			}
			valid.push(feat);
		}
		for (i = 0; i < valid.length; i++) {
			var bi = territoryFeatureBbox(valid[i]);
			for (var j = i + 1; j < valid.length; j++) {
				if (bboxesOverlap(bi, territoryFeatureBbox(valid[j]))) {
					overlapCount++;
				}
			}
		}
		return { features: valid, overlapCount: overlapCount, invalidCount: invalidCount };
	}

	function makeDebugFeatureCollection(items) {
		return {
			type: 'FeatureCollection',
			features: items
				.map(function (item) {
					return item.feature;
				})
				.filter(Boolean)
		};
	}

	function buildQuarterDebugFeatures(grid, originLat) {
		return grid.quarters
			.map(function (q) {
				var geom = cellsToPolygonGeometry(q.cells, grid, originLat);
				return geom
					? { type: 'Feature', geometry: geom, properties: { id: q.id, wscosm_debug: 'quarter' } }
					: null;
			})
			.filter(Boolean);
	}

	function cellToFeature(cell, grid, originLat, debugKind) {
		var ix = cell[0];
		var iy = cell[1];
		var x0 = grid.minX + ix * grid.cell;
		var y0 = grid.minY + iy * grid.cell;
		var x1 = x0 + grid.cell;
		var y1 = y0 + grid.cell;
		return {
			type: 'Feature',
			properties: { wscosm_debug: debugKind },
			geometry: {
				type: 'Polygon',
				coordinates: [
					closeRing([
						lonLatPoint(x0, y0, originLat),
						lonLatPoint(x1, y0, originLat),
						lonLatPoint(x1, y1, originLat),
						lonLatPoint(x0, y1, originLat)
					])
				]
			}
		};
	}

	function buildCellDebugFeatures(cells, grid, originLat, debugKind) {
		var limit = Math.min((cells || []).length, TERRITORY_CONFIG.maxDebugCells);
		var out = [];
		for (var i = 0; i < limit; i++) {
			out.push(cellToFeature(cells[i], grid, originLat, debugKind));
		}
		return out;
	}

	function generateRasterTerritoryAllocation(featureStore, bounds, progress) {
		var osmObjects = scanOsmObjects(featureStore, bounds);
		var obstacles = buildObstacleGeometry(osmObjects);
		var workingArea = buildWorkingArea(bounds);
		var freeArea = buildFreeArea(workingArea, obstacles);
		var gridCells = generateGrid(freeArea, TERRITORY_CONFIG.cellSizeMeters);
		var assignments = assignGridCellsToBuildings(
			gridCells,
			osmObjects.buildings,
			obstacles,
			TERRITORY_CONFIG,
			progress
		);
		var features = mergeCellsByBuilding(assignments, gridCells, osmObjects.originLat);
		var validationResult = validateTerritories(features);
		var obstacleCount =
			osmObjects.buildings.length +
			osmObjects.roads.length +
			osmObjects.railways.length +
			osmObjects.waters.length +
			osmObjects.barriers.length +
			osmObjects.obstacles.length;

		console.log('[RasterTerritory] Buildings:', osmObjects.buildings.length);
		console.log('[RasterTerritory] Roads:', osmObjects.roads.length);
		console.log('[RasterTerritory] Obstacles:', obstacleCount);
		console.log('[RasterTerritory] Grid cells:', gridCells.totalCells);
		console.log('[RasterTerritory] Assigned cells:', assignments.assignedCells.length);
		console.log('[RasterTerritory] Generated territories:', validationResult.features.length);
		console.log('[RasterTerritory] Validation:', validationResult);

		validationResult.features._territoryDebug = {
			gridCells: buildCellDebugFeatures(assignments.gridCells, gridCells, osmObjects.originLat, 'grid_cell'),
			assignedCells: buildCellDebugFeatures(
				assignments.assignedCells,
				gridCells,
				osmObjects.originLat,
				'assigned_cell'
			),
			unassignedCells: buildCellDebugFeatures(
				assignments.unassignedCells,
				gridCells,
				osmObjects.originLat,
				'unassigned_cell'
			),
			quarters: buildQuarterDebugFeatures(gridCells, osmObjects.originLat),
			obstacles: makeDebugFeatureCollection(
				osmObjects.roads.concat(osmObjects.railways, osmObjects.waters, osmObjects.barriers, osmObjects.obstacles)
			),
			territories: { type: 'FeatureCollection', features: validationResult.features.slice(0, TERRITORY_CONFIG.maxDebugCells) },
			stats: {
				buildings: osmObjects.buildings.length,
				roads: osmObjects.roads.length,
				obstacles: obstacleCount,
				gridCells: gridCells.totalCells,
				freeCells: assignments.totalFreeCells,
				assignedCells: assignments.assignedCells.length,
				unassignedCells: assignments.unassignedCells.length,
				territories: validationResult.features.length,
				overlaps: validationResult.overlapCount,
				invalid: validationResult.invalidCount
			}
		};
		return validationResult.features;
	}

	function buildRasterTerritories(featureStore, bounds, progress) {
		return generateRasterTerritoryAllocation(featureStore, bounds, progress);
	}

	function territoryScanStats(featureStore, bounds) {
		var osmObjects = scanOsmObjects(featureStore, bounds);
		var obstacles = buildObstacleGeometry(osmObjects);
		var freeArea = buildFreeArea(buildWorkingArea(bounds), obstacles);
		var gridCells = generateGrid(freeArea, TERRITORY_CONFIG.cellSizeMeters);
		var assignments = assignGridCellsToBuildings(gridCells, osmObjects.buildings, obstacles, TERRITORY_CONFIG);
		var obstacleCount =
			osmObjects.buildings.length +
			osmObjects.roads.length +
			osmObjects.railways.length +
			osmObjects.waters.length +
			osmObjects.barriers.length +
			osmObjects.obstacles.length;
		return {
			buildings: osmObjects.buildings.length,
			roads: osmObjects.roads.length,
			obstacles: obstacleCount,
			gridCells: gridCells.totalCells,
			assignedCells: assignments.assignedCells.length
		};
	}

	function buildRasterTerritoriesAsync(featureStore, mapBounds, progress, done) {
		window.setTimeout(function () {
			try {
				var features = buildRasterTerritories(featureStore, mapBounds, progress);
				if (!features.length) {
					done(new Error('not_enough_buildings'));
					return;
				}
				done(null, features);
			} catch (err) {
				console.error('[RasterTerritory] Build failed:', err);
				done(err);
			}
		}, 0);
	}

	function buildServerTerritoriesAsync(scanCtx, mapBounds, progress, done) {
		if (!scanCtx.d.territoryJobUrl) {
			scanCtx.territoryActivateUrl = '';
			buildRasterTerritoriesAsync(scanCtx.osmFeaturesByKey, mapBounds, progress, done);
			return;
		}
		scanCtx.territoryActivateUrl = '';
		var boundsPayload = {
			south: mapBounds.getSouth(),
			west: mapBounds.getWest(),
			north: mapBounds.getNorth(),
			east: mapBounds.getEast()
		};
		fetch(scanCtx.d.territoryJobUrl, restPostOptions({ bounds: boundsPayload, config: TERRITORY_CONFIG }))
			.then(function (r) {
				if (!r.ok) return Promise.reject(new Error('job_start_failed'));
				return r.json();
			})
			.then(function (job) {
				var statusUrl = job.status_url || '';
				var resultUrl = job.result_url || '';
				if (!statusUrl || !resultUrl) return Promise.reject(new Error('bad_job_response'));
				scanCtx.territoryActivateUrl = job.activate_url || '';
				function poll() {
					fetch(statusUrl, restFetchOptions())
						.then(function (r) {
							if (!r.ok) return Promise.reject(new Error('job_status_failed'));
							return r.json();
						})
						.then(function (status) {
							var cur = parseInt(status.current || 0, 10);
							var total = parseInt(status.total || 1, 10);
							if (progress) progress(cur, total, status.message || status.phase || '');
							if (status.status === 'done') {
								fetch(resultUrl, restFetchOptions())
									.then(function (r) {
										if (!r.ok) return Promise.reject(new Error('job_result_failed'));
										return r.json();
									})
									.then(function (fc) {
										var features = fc && Array.isArray(fc.features) ? fc.features : [];
										features._territoryDebug = fc && fc.debug ? fc.debug : null;
										if (!features.length) {
											scanCtx.territoryActivateUrl = '';
											done(new Error('not_enough_buildings'));
											return;
										}
										done(null, features);
									})
									.catch(function (err) {
										scanCtx.territoryActivateUrl = '';
										done(err);
									});
								return;
							}
							if (status.status === 'error' || status.status === 'missing') {
								scanCtx.territoryActivateUrl = '';
								done(new Error(status.message || 'territory_job_failed'));
								return;
							}
							window.setTimeout(poll, 800);
						})
						.catch(function (err) {
							scanCtx.territoryActivateUrl = '';
							done(err);
						});
				}
				poll();
			})
			.catch(function (err) {
				scanCtx.territoryActivateUrl = '';
				done(err);
			});
	}

	function drawTerritoryDebug(debugGroup, debugData) {
		if (!debugGroup) return;
		debugGroup.clearLayers();
		if (!TERRITORY_CONFIG.debug || !debugData) return;
		function addGeoJson(fc, options) {
			var layer = L.geoJSON(fc || { type: 'FeatureCollection', features: [] }, options || {});
			layer.eachLayer(function (item) {
				debugGroup.addLayer(item);
			});
		}
		addGeoJson({ type: 'FeatureCollection', features: debugData.gridCells || [] }, {
			style: function () {
				return { color: '#94a3b8', weight: 0.4, opacity: 0.35, fillColor: '#e2e8f0', fillOpacity: 0.04 };
			}
		});
		addGeoJson({ type: 'FeatureCollection', features: debugData.assignedCells || [] }, {
			style: function () {
				return { color: '#16a34a', weight: 0.5, opacity: 0.5, fillColor: '#86efac', fillOpacity: 0.12 };
			}
		});
		addGeoJson({ type: 'FeatureCollection', features: debugData.unassignedCells || [] }, {
			style: function () {
				return { color: '#f97316', weight: 0.5, opacity: 0.5, fillColor: '#fdba74', fillOpacity: 0.1 };
			}
		});
		var quarters = L.geoJSON({ type: 'FeatureCollection', features: debugData.quarters || [] }, {
			style: function () {
				return { fillColor: '#fef3c7', color: '#b45309', weight: 1, opacity: 0.9, fillOpacity: 0.08 };
			}
		});
		var blockers = L.geoJSON(debugData.obstacles || { type: 'FeatureCollection', features: [] }, {
			style: function (feat) {
				var kind = feat.properties && feat.properties.wscosm_kind;
				if (kind === 'water') return { color: '#2563eb', fillColor: '#60a5fa', weight: 2, fillOpacity: 0.22 };
				if (kind === 'railway' || kind === 'landuse_railway') return { color: '#111827', fillColor: '#9ca3af', weight: 2, fillOpacity: 0.16 };
				return { color: '#dc2626', fillColor: '#fca5a5', weight: 2, fillOpacity: 0.14 };
			}
		});
		quarters.eachLayer(function (layer) {
			debugGroup.addLayer(layer);
		});
		blockers.eachLayer(function (layer) {
			debugGroup.addLayer(layer);
		});
	}

	function renderTerritories(territoryGroup, features, debugGroup) {
		territoryGroup.clearLayers();
		var gj = L.geoJSON({ type: 'FeatureCollection', features: features }, {
			style: function () {
				return {
					fillColor: '#38bdf8',
					color: '#075985',
					weight: 0.8,
					opacity: 0.95,
					fillOpacity: 0.22
				};
			},
			onEachFeature: function (feat, layer) {
				var p = feat.properties || {};
				layer.bindPopup('<strong>' + $('<div/>').text(p.title || p.object_key || '').html() + '</strong>');
			}
		});
		gj.eachLayer(function (layer) {
			territoryGroup.addLayer(layer);
		});
		drawTerritoryDebug(debugGroup, features && features._territoryDebug);
	}

	function exportTerritoriesGeoJSON(features) {
		return {
			type: 'FeatureCollection',
			features: (features || []).map(function (feature) {
				return feature;
			})
		};
	}

	function placeTerritoriesBehindBuildings(scanCtx) {
		if (scanCtx.voronoiGroup && typeof scanCtx.voronoiGroup.eachLayer === 'function') {
			scanCtx.voronoiGroup.eachLayer(function (layer) {
				if (layer && typeof layer.bringToBack === 'function') {
					layer.bringToBack();
				}
			});
		}
		(scanCtx.kindOrder || []).forEach(function (kind) {
			var group = scanCtx.kinds && scanCtx.kinds[kind];
			if (!isBldgKind(kind) || !group || typeof group.eachLayer !== 'function') return;
			group.eachLayer(function (layer) {
				if (layer && typeof layer.bringToFront === 'function') {
					layer.bringToFront();
				}
			});
		});
	}

	function refreshLayersControl(scanCtx) {
		if (scanCtx.layersControl) {
			scanCtx.m.removeControl(scanCtx.layersControl);
			scanCtx.layersControl = null;
		}
		var ovl = buildOverlaysObject(
			scanCtx.m,
			scanCtx.Ltxt,
			scanCtx.labels,
			scanCtx.kinds,
			scanCtx.kindOrder,
			scanCtx.yardsGroup,
			scanCtx.centerGroup,
			scanCtx.voronoiGroup,
			scanCtx.territoryDebugGroup
		);
		scanCtx.layersControl = L.control.layers(null, ovl, {
			collapsed: false,
			position: 'topright'
		}).addTo(scanCtx.m);
	}

	function addScanControl(container, scanCtx) {
		var Ltxt = scanCtx.Ltxt;
		var ScanCtrl = L.Control.extend({
			options: { position: 'topleft' },
			onAdd: function (map) {
				var wrap = L.DomUtil.create('div', 'leaflet-bar wscosm-scan-wrap');
				var btn = L.DomUtil.create('button', 'wscosm-scan-btn', wrap);
				btn.type = 'button';
				btn.title = Ltxt.scanOsmHint || '';
				btn.appendChild(document.createTextNode(Ltxt.scanOsm || 'Scan'));
				L.DomEvent.disableClickPropagation(wrap);
				L.DomEvent.on(btn, 'dblclick', L.DomEvent.stopPropagation);
				L.DomEvent.on(btn, 'click', function (e) {
					L.DomEvent.stopPropagation(e);
					L.DomEvent.preventDefault(e);
					if (btn.disabled || !scanCtx.d.featuresUrl) {
						return;
					}
					var progressRow = ensureScanProgressRow(container);
					var bar = progressRow.querySelector('.wscosm-scan-progress__bar');
					var labelEl = progressRow.querySelector('.wscosm-scan-progress__label');
					var detailEl = progressRow.querySelector('.wscosm-scan-progress__detail');
					var progressId = makeScanProgressId();
					var pollBase = (cfg && cfg.scanProgressUrl) || '';
					var pollTimer = null;

					function stopProgressPoll() {
						if (pollTimer) {
							clearInterval(pollTimer);
							pollTimer = null;
						}
					}

					function applyProgressPayload(data) {
						if (!data || !bar || !labelEl) {
							return;
						}
						var phase = data.phase;
						var total = typeof data.total === 'number' ? data.total : 0;
						var saved = typeof data.saved === 'number' ? data.saved : 0;
						var cur = typeof data.current === 'number' ? data.current : 0;
						bar.style.background = '#15803d';
						if (phase === 'overpass' || phase === 'unknown') {
							labelEl.textContent = Ltxt.scanProgressOverpass || '';
							bar.style.width = '12%';
							if (detailEl) {
								detailEl.textContent = '';
							}
						} else if (phase === 'saving') {
							labelEl.textContent = Ltxt.scanProgressSaving || '';
							var pct =
								total > 0 ? Math.min(100, Math.round((cur / total) * 88) + 12) : 45;
							bar.style.width = pct + '%';
							if (detailEl) {
								detailEl.textContent =
									(Ltxt.scanProgressCounts || '') + ': ' + saved + (total ? ' / ' + total : '');
							}
						} else if (phase === 'done') {
							labelEl.textContent = Ltxt.scanProgressDone || '';
							bar.style.width = '100%';
							if (detailEl) {
								detailEl.textContent =
									(Ltxt.scanProgressCounts || '') + ': ' + saved + (total ? ' / ' + total : '');
							}
						} else if (phase === 'error') {
							labelEl.textContent = Ltxt.scanProgressError || '';
							bar.style.width = '100%';
							bar.style.background = '#b91c1c';
							if (detailEl) {
								detailEl.textContent = data.message || '';
							}
						}
					}

					progressRow.removeAttribute('hidden');
					applyProgressPayload({ phase: 'overpass', total: 0, saved: 0, current: 0 });

					if (pollBase) {
						pollTimer = setInterval(function () {
							var sep = pollBase.indexOf('?') >= 0 ? '&' : '?';
							fetch(pollBase + sep + 'progress_id=' + encodeURIComponent(progressId), restFetchOptions())
								.then(function (r) {
									return r.ok ? r.json() : {};
								})
								.then(applyProgressPayload)
								.catch(function () {});
						}, 380);
					}

					var reqUrl = scanViewportUrl(scanCtx.d.featuresUrl, map.getBounds(), progressId);
					btn.disabled = true;
					btn.classList.add('is-busy');
					fetch(reqUrl, restFetchOptions())
						.then(function (r) {
							if (!r.ok) {
								return Promise.reject(new Error('http'));
							}
							return r.json();
						})
						.then(function (ofc) {
							var feats = ofc && Array.isArray(ofc.features) ? ofc.features : [];
							if (!feats.length) {
								return;
							}
							var ergoCtxScan = {
								cityId: scanCtx.d.cityId,
								yardErgoAtUrl: scanCtx.d.yardErgoAtUrl || '',
								i18n: cfg.i18n || {}
							};
							var added = fillOsmFeaturesIntoKinds(
								scanCtx.kinds,
								scanCtx.kindOrder,
								ofc,
								ergoCtxScan,
								scanCtx.osmSeenKeys,
								scanCtx.osmFeaturesByKey
							);
							if (!added) {
								return;
							}
							scanCtx.voronoiFeatures = [];
							scanCtx.voronoiGroup.clearLayers();
							if (scanCtx.territoryDebugGroup) {
								scanCtx.territoryDebugGroup.clearLayers();
							}
							refreshLayersControl(scanCtx);
							renderBuildingLegend(
								container,
								scanCtx.kindOrder,
								scanCtx.kinds,
								scanCtx.Ltxt,
								scanCtx.labels
							);
							if (detailEl) {
								try {
									var stats = territoryScanStats(scanCtx.osmFeaturesByKey, map.getBounds());
									detailEl.textContent =
										(Ltxt.voronoiRebuildAfterScan || detailEl.textContent) +
										' | buildings: ' +
										stats.buildings +
										', roads: ' +
										stats.roads +
										', obstacles: ' +
										stats.obstacles +
										', grid cells: ' +
										stats.gridCells +
										', assigned cells: ' +
										stats.assignedCells;
								} catch (errStats) {
									detailEl.textContent = Ltxt.voronoiRebuildAfterScan || detailEl.textContent;
								}
							}
						})
						.catch(function () {
							stopProgressPoll();
							applyProgressPayload({
								phase: 'error',
								message: Ltxt.scanOsmError || ''
							});
							window.alert(Ltxt.scanOsmError || 'Error');
						})
						.finally(function () {
							stopProgressPoll();
							btn.disabled = false;
							btn.classList.remove('is-busy');
							function hideProgressRow() {
								if (progressRow) {
									progressRow.setAttribute('hidden', 'hidden');
								}
							}
							if (pollBase && progressId) {
								var sep2 = pollBase.indexOf('?') >= 0 ? '&' : '?';
								fetch(
									pollBase + sep2 + 'progress_id=' + encodeURIComponent(progressId),
									restFetchOptions()
								)
									.then(function (r) {
										return r.ok ? r.json() : {};
									})
									.then(applyProgressPayload)
									.finally(function () {
										setTimeout(hideProgressRow, 950);
									});
							} else {
								setTimeout(hideProgressRow, 950);
							}
						});
				});
				return wrap;
			}
		});
		(new ScanCtrl()).addTo(scanCtx.m);
	}

	function addVoronoiControl(container, scanCtx) {
		var Ltxt = scanCtx.Ltxt;
		var VoronoiCtrl = L.Control.extend({
			options: { position: 'topleft' },
			onAdd: function (map) {
				var wrap = L.DomUtil.create('div', 'leaflet-bar wscosm-voronoi-wrap');
				var buildBtn = L.DomUtil.create('button', 'wscosm-voronoi-btn', wrap);
				var saveBtn = L.DomUtil.create('button', 'wscosm-voronoi-btn', wrap);
				buildBtn.type = 'button';
				saveBtn.type = 'button';
				buildBtn.title = Ltxt.buildVoronoiHint || '';
				saveBtn.title = Ltxt.saveVoronoiHint || '';
				buildBtn.appendChild(document.createTextNode(Ltxt.buildVoronoi || 'Build Voronoi'));
				saveBtn.appendChild(document.createTextNode(Ltxt.saveVoronoi || 'Save yards'));
				saveBtn.disabled = !scanCtx.d.canSaveVoronoi;
				if (!scanCtx.d.canSaveVoronoi) {
					saveBtn.title = Ltxt.voronoiSaveDisabled || saveBtn.title;
				}

				function setBusy(on) {
					buildBtn.disabled = !!on;
					saveBtn.disabled = !!on || !scanCtx.d.canSaveVoronoi;
					buildBtn.classList.toggle('is-busy', !!on);
					saveBtn.classList.toggle('is-busy', !!on);
				}

				function setProgress(phase, current, total, message) {
					var row = ensureScanProgressRow(container);
					var bar = row.querySelector('.wscosm-scan-progress__bar');
					var labelEl = row.querySelector('.wscosm-scan-progress__label');
					var detailEl = row.querySelector('.wscosm-scan-progress__detail');
					row.removeAttribute('hidden');
					if (bar) {
						var pct = total > 0 ? Math.max(8, Math.min(100, Math.round((current / total) * 100))) : 12;
						bar.style.width = pct + '%';
						bar.style.background = phase === 'error' ? '#b91c1c' : '#15803d';
					}
					if (labelEl) labelEl.textContent = message || '';
					if (detailEl) detailEl.textContent = total > 0 ? current + ' / ' + total : '';
					if (phase === 'done' || phase === 'error') {
						window.setTimeout(function () {
							row.setAttribute('hidden', 'hidden');
						}, 1200);
					}
				}

				function buildPreview() {
					setBusy(true);
					setProgress('build', 0, 0, Ltxt.voronoiBuilding || 'Building Voronoi');
					// Кнопка запускает альтернативный raster allocation вместо классического Voronoi.
					buildServerTerritoriesAsync(
						scanCtx,
						map.getBounds(),
						function (cur, total, message) {
							setProgress('build', cur, total, message || Ltxt.voronoiBuilding || 'Building Voronoi');
						},
						function (err, features) {
							setBusy(false);
							if (err || !features || !features.length) {
								scanCtx.voronoiFeatures = [];
								setProgress('error', 1, 1, Ltxt.voronoiNoBuildings || Ltxt.voronoiError || 'Error');
								window.alert(Ltxt.voronoiNoBuildings || Ltxt.voronoiError || 'Error');
								return;
							}
							scanCtx.voronoiFeatures = features;
							renderTerritories(scanCtx.voronoiGroup, features, scanCtx.territoryDebugGroup);
							refreshLayersControl(scanCtx);
							placeTerritoriesBehindBuildings(scanCtx);
							setProgress('done', features.length, features.length, Ltxt.voronoiReady || 'Ready');
						}
					);
				}

				function savePreview() {
					if (!scanCtx.d.canSaveVoronoi || !scanCtx.d.voronoiSaveUrl) {
						window.alert(Ltxt.voronoiSaveDisabled || 'Saving is disabled.');
						return;
					}
					var features = scanCtx.voronoiFeatures || [];
					if (!features.length) {
						buildPreview();
						return;
					}
					function afterSaveSuccess(saved) {
						setBusy(false);
						scanCtx.voronoiFeatures = [];
						scanCtx.territoryActivateUrl = '';
						if (scanCtx.voronoiGroup && typeof scanCtx.voronoiGroup.clearLayers === 'function') {
							scanCtx.voronoiGroup.clearLayers();
						}
						if (scanCtx.territoryDebugGroup && typeof scanCtx.territoryDebugGroup.clearLayers === 'function') {
							scanCtx.territoryDebugGroup.clearLayers();
						}
						if (typeof scanCtx.reloadYardsLayer === 'function') {
							scanCtx.reloadYardsLayer();
						} else {
							if (scanCtx.yardsGroup && typeof scanCtx.yardsGroup.clearLayers === 'function') {
								scanCtx.yardsGroup.clearLayers();
							}
							refreshLayersControl(scanCtx);
							placeTerritoriesBehindBuildings(scanCtx);
						}
						setProgress(
							'done',
							features.length,
							features.length,
							(Ltxt.voronoiSaved || 'Saved') + ': ' + saved
						);
					}
					setBusy(true);
					if (scanCtx.territoryActivateUrl) {
						setProgress('save', 0, 1, Ltxt.voronoiSaving || 'Saving');
						fetch(scanCtx.territoryActivateUrl, restPostOptions({}))
							.then(function (r) {
								if (!r.ok) return Promise.reject(new Error('http'));
								return r.json();
							})
							.then(function (data) {
								scanCtx.territoryActivateUrl = '';
								var saved = parseInt(data.saved || 0, 10);
								afterSaveSuccess(saved);
							})
							.catch(function () {
								setBusy(false);
								setProgress('error', 0, features.length, Ltxt.voronoiError || 'Error');
								window.alert(Ltxt.voronoiError || 'Error');
							});
						return;
					}
					var geojson = exportTerritoriesGeoJSON(features);
					var batchSize = 250;
					var offset = 0;
					var saved = 0;
					function sendNext() {
						var batch = geojson.features.slice(offset, offset + batchSize);
						if (!batch.length) {
							afterSaveSuccess(saved);
							return;
						}
						setProgress('save', offset, geojson.features.length, Ltxt.voronoiSaving || 'Saving');
						fetch(scanCtx.d.voronoiSaveUrl, restPostOptions({ features: batch, replace_existing: offset === 0 }))
							.then(function (r) {
								if (!r.ok) return Promise.reject(new Error('http'));
								return r.json();
							})
							.then(function (data) {
								saved += parseInt(data.saved || 0, 10);
								offset += batch.length;
								setProgress('save', offset, geojson.features.length, (Ltxt.voronoiSaving || 'Saving') + ': ' + saved);
								window.setTimeout(sendNext, 0);
							})
							.catch(function () {
								setBusy(false);
								setProgress('error', offset, features.length, Ltxt.voronoiError || 'Error');
								window.alert(Ltxt.voronoiError || 'Error');
							});
					}
					sendNext();
				}

				L.DomEvent.disableClickPropagation(wrap);
				L.DomEvent.on(buildBtn, 'click', function (e) {
					L.DomEvent.stopPropagation(e);
					L.DomEvent.preventDefault(e);
					if (!buildBtn.disabled) buildPreview();
				});
				L.DomEvent.on(saveBtn, 'click', function (e) {
					L.DomEvent.stopPropagation(e);
					L.DomEvent.preventDefault(e);
					if (!saveBtn.disabled) savePreview();
				});
				return wrap;
			}
		});
		(new VoronoiCtrl()).addTo(scanCtx.m);
	}

	function renderBuildingLegend(mapEl, kindOrder, kinds, Ltxt, labels) {
		var prev = mapEl.parentNode && mapEl.parentNode.querySelector('.wscosm-bldg-legend');
		if (prev) {
			prev.remove();
		}
		var rows = [];
		kindOrder.forEach(function (k) {
			if (!isBldgKind(k) || !kinds[k] || kinds[k].getLayers().length === 0) return;
			var lab = (labels && labels[k]) || k;
			var bc = bldgColors(k);
			rows.push(
				'<div class="wscosm-bldg-legend__row"><span class="wscosm-bldg-legend__sw" style="background:' +
					bc.fill +
					';border-color:' +
					bc.stroke +
					'"></span><span class="wscosm-bldg-legend__txt">' +
					$('<div/>').text(lab).html() +
					'</span></div>'
			);
		});
		if (!rows.length) return;
		var wrap = document.createElement('div');
		wrap.className = 'wscosm-bldg-legend';
		wrap.innerHTML =
			'<div class="wscosm-bldg-legend__title">' +
			$('<div/>').text(Ltxt.legendBuildings || '').html() +
			'</div>' +
			rows.join('');
		if (mapEl.parentNode) {
			mapEl.parentNode.insertBefore(wrap, mapEl.nextSibling);
		}
	}

	function fitMapToLoadedLayers(m, yardsGroup, kinds, kindOrder) {
		if (!m || m._wscosmDidInitialFit) return;
		var fitB = null;
		if (yardsGroup && yardsGroup.getLayers().length > 0) {
			fitB = yardsGroup.getBounds();
		} else {
			var bldgGroups = [];
			(kindOrder || []).forEach(function (k) {
				if (isBldgKind(k) && kinds[k] && kinds[k].getLayers().length) {
					bldgGroups.push(kinds[k]);
				}
			});
			fitB = unionBounds(bldgGroups);
		}
		if (fitB && fitB.isValid()) {
			try {
				m._wscosmDidInitialFit = true;
				m.fitBounds(fitB.pad(0.08));
			} catch (err) {}
		}
	}

	function initYardsMap(container, d) {
		if (typeof L === 'undefined') {
			return;
		}
		if (container._wscosmLeafletMap) {
			container._wscosmLeafletMap.remove();
			container._wscosmLeafletMap = null;
		}

		var m = L.map(container, { zoomControl: true }).setView([d.lat, d.lng], d.zoom || 14);
		container._wscosmLeafletMap = m;

		L.tileLayer(d.tileUrl, {
			attribution: d.tileAttrib,
			maxZoom: 19,
			subdomains: 'abcd'
		}).addTo(m);

		var Ltxt = cfg.i18n || {};
		var labels = cfg.buildingKindLabels || {};

		var yardsGroup = L.featureGroup();
		var centerGroup = L.layerGroup();
		L.circleMarker([d.lat, d.lng], {
			radius: 9,
			fillColor: '#ef4444',
			color: '#fff',
			weight: 2,
			fillOpacity: 0.9
		})
			.addTo(centerGroup)
			.bindPopup('<strong>' + (d.cityName || '') + '</strong>')
			.bindTooltip(Ltxt.layerCenter || 'Center', { direction: 'top' });
		centerGroup.addTo(m);

		var shell = createEmptyKindGroups();
		var kinds = shell.kinds;
		var kindOrder = shell.kindOrder;
		var ergoCtx = {
			cityId: d.cityId,
			yardErgoAtUrl: d.yardErgoAtUrl || '',
			i18n: cfg.i18n || {}
		};
		var osmSeenKeys = {};
		var osmFeaturesByKey = {};
		var voronoiGroup = L.featureGroup();
		var territoryDebugGroup = L.featureGroup();
		var scanCtx = {
			m: m,
			d: d,
			Ltxt: Ltxt,
			labels: labels,
			yardsGroup: yardsGroup,
			centerGroup: centerGroup,
			kinds: kinds,
			kindOrder: kindOrder,
			osmSeenKeys: osmSeenKeys,
			osmFeaturesByKey: osmFeaturesByKey,
			voronoiGroup: voronoiGroup,
			territoryDebugGroup: territoryDebugGroup,
			voronoiFeatures: [],
			layersControl: null,
			reloadYardsLayer: null
		};
		refreshLayersControl(scanCtx);
		if (d.featuresUrl && d.canScanOsm) {
			addScanControl(container, scanCtx);
		}
		addVoronoiControl(container, scanCtx);

		function loadYardsLayer() {
			if (!d.yardsUrl) return;
			fetch(d.yardsUrl, restFetchOptions())
				.then(function (r) {
					return r.ok ? r.json() : { features: [] };
				})
				.catch(function () {
					return { features: [] };
				})
				.then(function (yfc) {
					yardsGroup.clearLayers();
					var yardFeatures = yfc && Array.isArray(yfc.features) ? yfc.features : [];
					if (d.yardsCountElementId) {
						var countEl = document.getElementById(d.yardsCountElementId);
						if (countEl) {
							countEl.textContent = String(yardFeatures.length);
						}
					}
					if (d.chartElementId && d.chart && d.chart.labels && d.chart.labels.length && window.WSPChart && typeof Chart !== 'undefined') {
						window.WSPChart.render(d.chartElementId, buildYardMeansChart(d.chart, yardFeatures));
					}
					if (yardFeatures.length) {
						var ygj = L.geoJSON(yfc, {
							style: function (feat) {
								var v = feat.properties ? feat.properties.index : null;
								var c = yardColorForIndex(v);
								return {
									fillColor: c.fill,
									color: c.stroke,
									weight: 0.7,
									opacity: 1,
									fillOpacity: 0.55
								};
							},
							onEachFeature: function (feat, layer) {
								var p = feat.properties || {};
								var html = '<div class="wsp-marker-popup-content"><strong>' + (p.title || '') + '</strong>';
								if (p.popup) html += '<br>' + p.popup;
								html += '</div>';
								layer.bindPopup(html);
							}
						});
						ygj.eachLayer(function (layer) {
							yardsGroup.addLayer(layer);
						});
					}
					refreshLayersControl(scanCtx);
					fitMapToLoadedLayers(m, yardsGroup, kinds, kindOrder);
					placeTerritoriesBehindBuildings(scanCtx);
				});
		}

		function loadOsmLayer() {
			var osmUrl = d.featuresUrl ? localViewportUrl(d.featuresUrl, m.getBounds()) : '';
			if (!osmUrl) return;
			fetch(osmUrl, restFetchOptions())
				.then(function (r) {
					return r.ok ? r.json() : { features: [] };
				})
				.catch(function () {
					return { features: [] };
				})
				.then(function (ofc) {
					fillOsmFeaturesIntoKinds(kinds, kindOrder, ofc, ergoCtx, osmSeenKeys, osmFeaturesByKey);
					refreshLayersControl(scanCtx);
					renderBuildingLegend(container, kindOrder, kinds, Ltxt, labels);
					fitMapToLoadedLayers(m, yardsGroup, kinds, kindOrder);
				});
		}

		loadYardsLayer();
		scanCtx.reloadYardsLayer = loadYardsLayer;
		loadOsmLayer();
	}

	function loadCityDetail($panel, cityId, iso2) {
		var $detail = $panel.find('#wscosm-ct-detail');
		$detail.html('<p class="wscosm-ct-loading">' + (cfg.i18n.loading || '…') + '</p>');

		$.post(cfg.ajaxUrl, {
			action: 'wscosm_country_city',
			nonce: cfg.nonce,
			city_id: cityId,
			iso2: iso2
		})
			.done(function (res) {
				if (!res || !res.success || !res.data) {
					$detail.html('<p class="wsp-error">' + (cfg.i18n.error || 'Error') + '</p>');
					return;
				}
				var d = res.data;
				var countId = 'wscosm-ct-yards-count-' + d.cityId + '-' + Date.now();
				var html =
					'<p class="wscosm-ct-stats"><strong>' +
					(cfg.i18n.yards || '') +
					':</strong> <span id="' +
					countId +
					'">' +
					(typeof d.yardsCount === 'number' ? d.yardsCount : '…') +
					'</span>' +
					(d.yardsCount === 0
						? ' — <span class="wsp-muted">' + (cfg.i18n.noYards || '') + '</span>'
						: '') +
					'</p>';
				html +=
					'<p class="wscosm-ct-stats"><strong>' +
					(cfg.i18n.osmObjects || '') +
					':</strong> ' +
					(typeof d.osmObjectsCount === 'number' ? d.osmObjectsCount : 0) +
					'</p>';

				var mapId = 'wscosm-ct-map-' + d.cityId;
				html +=
					'<h4 class="wsp-section-title" style="margin-top:1rem;">' + (cfg.i18n.mapTitle || 'Map') + '</h4>';
				html +=
					'<div id="' +
					mapId +
					'" class="wsp-minimap ergo-map-container" style="height:420px;background:#d4e6f1;margin-bottom:1rem;"></div>';

				var chartId = '';
				if (d.chart && d.chart.labels && d.chart.labels.length) {
					chartId = 'wscosm-ct-chart-' + d.cityId + '-' + Date.now();
					html += '<div class="wsp-chart-wrap">';
					if (d.chart.title) {
						html += '<h4 class="wsp-chart-title">' + $('<div/>').text(d.chart.title).html() + '</h4>';
					}
					html +=
						'<div class="wsp-chart-canvas-wrap" style="position:relative;height:280px;"><canvas id="' +
						chartId +
						'"></canvas></div></div>';
				}

				$detail.html(html);

				var el = document.getElementById(mapId);
				if (el) {
					d.yardsCountElementId = countId;
					d.chartElementId = chartId;
					initYardsMap(el, d);
				}
			})
			.fail(function () {
				$detail.html('<p class="wsp-error">' + (cfg.i18n.error || 'Error') + '</p>');
			});
	}

	function bindCountryTab($panel, iso2) {
		$panel = $($panel || document);
		var $shell = $panel.find('.wscosm-country-tab-shell');
		if (!$shell.length) return;

		var shellIso = $shell.data('iso2') || iso2;
		var $sel = $panel.find('#wscosm-ct-city');

		$sel.off('change.wscosm').on('change.wscosm', function () {
			var cid = parseInt($(this).val(), 10) || 0;
			if (!cid) {
				$panel.find('#wscosm-ct-detail').empty();
				return;
			}
			loadCityDetail($panel, cid, shellIso);
		});

		var opts = $sel.find('option');
		if (opts.length === 2) {
			$sel.val(opts.eq(1).attr('value')).trigger('change');
		}
	}

	function bindExistingCountryTabs() {
		$('.wscosm-country-tab-shell').each(function () {
			var $shell = $(this);
			var $panel = $shell.closest('.wsp-tab-panel, .wsp-tab-content, .wsp-country-tab-panel');
			bindCountryTab($panel.length ? $panel : $shell.parent(), $shell.data('iso2') || '');
		});
	}

	$(document).on('wsp:tab:loaded', function (e, tabId, iso2, $panel) {
		if (tabId !== 'courtyard_osm') return;
		bindCountryTab($panel, iso2);
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindExistingCountryTabs);
	} else {
		bindExistingCountryTabs();
	}
})(jQuery);
