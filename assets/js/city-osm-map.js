/**
 * Карта придомовой среды: полигоны из REST эргономики + GeoJSON OSM.
 */
(function () {
	'use strict';

	var cfg = typeof wscosmCityMap === 'undefined' ? null : wscosmCityMap;
	if (!cfg || !cfg.mapId) {
		return;
	}

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
		var t = (val - 0) / 100;
		if (t < 0) t = 0;
		if (t > 1) t = 1;
		var stops = ['#dc2626', '#facc15', '#16a34a'];
		var n = stops.length - 1;
		var seg = t * n;
		var i = Math.floor(seg);
		var u = seg - i;
		if (i >= n) {
			return { fill: stops[n], stroke: '#1e293b' };
		}
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
		var a = hex(stops[i]);
		var b = hex(stops[i + 1]);
		var r = Math.round(a.r + (b.r - a.r) * u);
		var g = Math.round(a.g + (b.g - a.g) * u);
		var bl = Math.round(a.b + (b.b - a.b) * u);
		var fill = 'rgb(' + r + ',' + g + ',' + bl + ')';
		return { fill: fill, stroke: '#1e293b' };
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
		var base = { color: '#334155', weight: 2, opacity: 0.9 };
		if (geomType === 'LineString' || geomType === 'MultiLineString') {
			base.weight = kind === 'path' ? 4 : 2;
			base.color =
				kind === 'path' ? '#1d4ed8' : kind === 'playground' ? '#db2777' : '#64748b';
			base.fillOpacity = 0;
			base.fill = false;
			return base;
		}
		if (geomType === 'Polygon' || geomType === 'MultiPolygon') {
			base.fillOpacity = kind === 'landuse_green' ? 0.28 : 0.35;
			base.color = kind === 'playground' ? '#be185d' : '#15803d';
			base.fillColor = kind === 'playground' ? '#fbcfe8' : '#bbf7d0';
			base.weight = 1.5;
			return base;
		}
		return base;
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
				fillOpacity: 0.88
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

	function osmPopup(props) {
		var parts = ['<div class="wscosm-popup">'];
		if (props.name) {
			parts.push('<strong>' + String(props.name) + '</strong><br>');
		}
		parts.push('<span class="wscosm-popup-kind">' + String(props.wscosm_kind || '') + '</span>');
		var keys = Object.keys(props).filter(function (k) {
			return k.indexOf('tag_') === 0;
		});
		keys.sort();
		keys.slice(0, 12).forEach(function (k) {
			var short = k.replace(/^tag_/, '');
			parts.push('<br><code>' + short + '</code>: ' + String(props[k]));
		});
		parts.push('</div>');
		return parts.join('');
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

	function featuresUrlForBounds(featuresUrl, bounds) {
		if (!featuresUrl || !bounds) {
			return featuresUrl;
		}
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

	function initMap() {
		if (typeof L === 'undefined') {
			setTimeout(initMap, 120);
			return;
		}
		var el = document.getElementById(cfg.mapId);
		if (!el || el._leaflet_id) {
			return;
		}

		var m = L.map(el, {
			zoomControl: true,
			attributionControl: true
		}).setView([cfg.lat, cfg.lng], cfg.zoom);

		L.tileLayer(cfg.tileUrl, {
			attribution: cfg.tileAttrib,
			maxZoom: 19,
			subdomains: 'abcd'
		}).addTo(m);

		var overlays = {};
		var yardsGroup = L.featureGroup();
		var layersCfg = cfg.layers || {};
		var labels = cfg.buildingKindLabels || {};

		var yardsPromise = cfg.yardsUrl
			? fetch(cfg.yardsUrl, { credentials: 'same-origin', cache: 'no-store' })
					.then(function (r) {
						return r.ok ? r.json() : { features: [] };
					})
					.catch(function () {
						return { features: [] };
					})
			: Promise.resolve({ features: [] });

		var osmUrl = featuresUrlForBounds(cfg.featuresUrl, m.getBounds());
		var osmPromise = osmUrl
			? fetch(osmUrl, { credentials: 'same-origin', cache: 'no-store' })
					.then(function (r) {
						return r.ok ? r.json() : { features: [] };
					})
					.catch(function () {
						return { features: [] };
					})
			: Promise.resolve({ features: [] });

		Promise.all([yardsPromise, osmPromise]).then(function (results) {
			var yfc = results[0];
			if (yfc && yfc.features && yfc.features.length) {
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
						if (p.popup) {
							html += '<br>' + p.popup;
						}
						html += '</div>';
						layer.bindPopup(html);
						if (p.title) {
							layer.bindTooltip(p.title, { direction: 'top', sticky: true });
						}
					}
				});
				ygj.eachLayer(function (layer) {
					yardsGroup.addLayer(layer);
				});
			}

			var bOrder = (cfg.buildingKindOrder || Object.keys(BLDS)).slice();
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

			var ofc = results[1];
			if (ofc && ofc.features) {
				ofc.features.forEach(function (feat) {
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
					var gj = L.geoJSON(feat, {
						style: function (f) {
							var g = f.geometry && f.geometry.type;
							return osmStyle(kind, g);
						},
						pointToLayer: function (f, latlng) {
							return L.circleMarker(latlng, osmPointStyle(kind));
						},
						onEachFeature: function (f, layer) {
							layer.bindPopup(osmPopup(f.properties || {}));
						}
					});
					gj.eachLayer(function (layer) {
						target.addLayer(layer);
					});
				});
			}

			kindOrder.forEach(function (k) {
				var g = kinds[k];
				if (!g || g.getLayers().length === 0) {
					return;
				}
				if (k !== 'path') {
					g.addTo(m);
				}
				var title = isBldgKind(k) ? labels[k] || layersCfg[k] || k : layersCfg[k] || k;
				overlays[title] = g;
			});

			if (yardsGroup.getLayers().length > 0) {
				yardsGroup.addTo(m);
				overlays[layersCfg.yards] = yardsGroup;
			}

			if (Object.keys(overlays).length) {
				L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(m);
			}

			var fitB = null;
			if (yardsGroup.getLayers().length > 0) {
				fitB = yardsGroup.getBounds();
			} else {
				var bldgGroups = [];
				kindOrder.forEach(function (k) {
					if (isBldgKind(k) && kinds[k] && kinds[k].getLayers().length) {
						bldgGroups.push(kinds[k]);
					}
				});
				fitB = unionBounds(bldgGroups);
			}
			if (fitB && fitB.isValid()) {
				try {
					m.fitBounds(fitB.pad(0.08));
				} catch (e) {}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMap);
	} else {
		initMap();
	}
})();
