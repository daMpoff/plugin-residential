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

	function fillOsmFeaturesIntoKinds(kinds, kindOrder, ofc, ergoCtx) {
		if (!ofc || !ofc.features) {
			return;
		}
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
		});
	}

	function removeOsmLayersFromMap(map, kinds, kindOrder) {
		kindOrder.forEach(function (k) {
			var g = kinds[k];
			if (!g) {
				return;
			}
			if (map.hasLayer(g)) {
				map.removeLayer(g);
			}
			g.clearLayers();
		});
	}

	function buildOverlaysObject(m, Ltxt, labels, kinds, kindOrder, yardsGroup, centerGroup) {
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
			yardsGroup.addTo(m);
			overlays[Ltxt.layerYards || 'Yards'] = yardsGroup;
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
							if (scanCtx.layersControl) {
								map.removeControl(scanCtx.layersControl);
								scanCtx.layersControl = null;
							}
							removeOsmLayersFromMap(map, scanCtx.kinds, scanCtx.kindOrder);
							var shell = createEmptyKindGroups();
							scanCtx.kinds = shell.kinds;
							scanCtx.kindOrder = shell.kindOrder;
							var ergoCtxScan = {
								cityId: scanCtx.d.cityId,
								yardErgoAtUrl: scanCtx.d.yardErgoAtUrl || '',
								i18n: cfg.i18n || {}
							};
							fillOsmFeaturesIntoKinds(scanCtx.kinds, scanCtx.kindOrder, ofc, ergoCtxScan);
							var ovl = buildOverlaysObject(
								map,
								scanCtx.Ltxt,
								scanCtx.labels,
								scanCtx.kinds,
								scanCtx.kindOrder,
								scanCtx.yardsGroup,
								scanCtx.centerGroup
							);
							scanCtx.layersControl = L.control.layers(null, ovl, {
								collapsed: false,
								position: 'topright'
							}).addTo(map);
							renderBuildingLegend(
								container,
								scanCtx.kindOrder,
								scanCtx.kinds,
								scanCtx.Ltxt,
								scanCtx.labels
							);
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
		var yardsPromise = d.yardsUrl
			? fetch(d.yardsUrl, restFetchOptions())
					.then(function (r) {
						return r.ok ? r.json() : { features: [] };
					})
					.catch(function () {
						return { features: [] };
					})
			: Promise.resolve({ features: [] });

		var osmUrl = d.featuresUrl ? localViewportUrl(d.featuresUrl, m.getBounds()) : '';
		var osmPromise = osmUrl
			? fetch(osmUrl, restFetchOptions())
					.then(function (r) {
						return r.ok ? r.json() : { features: [] };
					})
					.catch(function () {
						return { features: [] };
					})
			: Promise.resolve({ features: [] });

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
						if (p.popup) html += '<br>' + p.popup;
						html += '</div>';
						layer.bindPopup(html);
					}
				});
				ygj.eachLayer(function (layer) {
					yardsGroup.addLayer(layer);
				});
			}

			var shell = createEmptyKindGroups();
			var kinds = shell.kinds;
			var kindOrder = shell.kindOrder;

			var ergoCtx = {
				cityId: d.cityId,
				yardErgoAtUrl: d.yardErgoAtUrl || '',
				i18n: cfg.i18n || {}
			};
			fillOsmFeaturesIntoKinds(kinds, kindOrder, results[1], ergoCtx);

			var overlays = buildOverlaysObject(m, Ltxt, labels, kinds, kindOrder, yardsGroup, centerGroup);

			var layersControl = null;
			if (Object.keys(overlays).length) {
				layersControl = L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(m);
			}

			var scanCtx = {
				m: m,
				d: d,
				Ltxt: Ltxt,
				labels: labels,
				yardsGroup: yardsGroup,
				centerGroup: centerGroup,
				kinds: kinds,
				kindOrder: kindOrder,
				layersControl: layersControl
			};
			if (d.featuresUrl && d.canScanOsm) {
				addScanControl(container, scanCtx);
			}

			renderBuildingLegend(container, kindOrder, kinds, Ltxt, labels);

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
				} catch (err) {}
			}
		});
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
				var html =
					'<p class="wscosm-ct-stats"><strong>' +
					(cfg.i18n.yards || '') +
					':</strong> ' +
					d.yardsCount +
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
					initYardsMap(el, d);
				}
				if (chartId && window.WSPChart && typeof Chart !== 'undefined') {
					window.WSPChart.render(chartId, d.chart);
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
