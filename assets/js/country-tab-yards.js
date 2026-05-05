/**
 * Country tab: city selector + map + simple courtyard buffer by selected building.
 */
(function ($) {
	'use strict';

	var cfg = typeof wscosmCountryTab === 'undefined' ? null : wscosmCountryTab;
	if (!cfg) return;

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

	function makeBufferProgressId() {
		try {
			var arr = new Uint8Array(16);
			if (window.crypto && window.crypto.getRandomValues) {
				window.crypto.getRandomValues(arr);
			} else {
				for (var i = 0; i < 16; i++) arr[i] = Math.floor(Math.random() * 256);
			}
			var hex = '';
			for (var j = 0; j < arr.length; j++)
				hex += ('0' + (arr[j] & 255).toString(16)).slice(-2);
			return hex;
		} catch (e) {
			return '';
		}
	}

	function bufferProgressCountsLabel(tpl, cur, total) {
		var c = String(cur);
		var t = String(total);
		if (!tpl || tpl.indexOf('%') < 0) return c + ' / ' + t;
		return tpl.replace(/%1\$d/g, c).replace(/%2\$d/g, t);
	}

	function localViewportUrl(featuresUrl, bounds, forceLive) {
		if (!featuresUrl || !bounds) return '';
		var u = new URL(featuresUrl, window.location.href);
		u.searchParams.set('south', String(bounds.getSouth()));
		u.searchParams.set('west', String(bounds.getWest()));
		u.searchParams.set('north', String(bounds.getNorth()));
		u.searchParams.set('east', String(bounds.getEast()));
		u.searchParams.set('source', forceLive ? 'live' : 'local');
		if (forceLive) {
			u.searchParams.set('refresh', '1');
		}
		return u.toString();
	}

	function isBldgKind(kind) {
		return kind && String(kind).indexOf('bldg_') === 0;
	}

	function isPolygonGeometry(geomType) {
		return geomType === 'Polygon' || geomType === 'MultiPolygon';
	}

	function osmFeatureKey(feat) {
		if (!feat || !feat.properties) return '';
		var p = feat.properties || {};
		var t = p.wscosm_osm_el_type || '';
		var id = p.wscosm_osm_id || '';
		if (t && id) return String(t) + ':' + String(id);
		if (p.object_key) return String(p.object_key);
		return '';
	}

	function buildingStyle() {
		return {
			fillColor: '#c4b5fd',
			color: '#5b21b6',
			weight: 1,
			opacity: 1,
			fillOpacity: 0.38
		};
	}

	function selectedBuildingStyle() {
		return {
			fillColor: '#f59e0b',
			color: '#b45309',
			weight: 2,
			opacity: 1,
			fillOpacity: 0.55
		};
	}

	function objectStyle(kind, geomType) {
		if (geomType === 'LineString' || geomType === 'MultiLineString') {
			return { color: '#1f2937', weight: 2, opacity: 0.9, fill: false, fillOpacity: 0 };
		}
		if (geomType === 'Polygon' || geomType === 'MultiPolygon') {
			return { fillColor: '#bbf7d0', color: '#15803d', weight: 1.2, opacity: 1, fillOpacity: 0.35 };
		}
		return { color: '#1f2937', weight: 2 };
	}

	function objectPointStyle(kind) {
		var colors = {
			bench: '#92400e',
			light: '#ca8a04',
			playground: '#db2777',
			waste_basket: '#475569',
			path: '#1d4ed8',
			other: '#64748b'
		};
		return {
			radius: kind === 'playground' ? 7 : 5,
			fillColor: colors[kind] || colors.other,
			color: '#fff',
			weight: 2,
			opacity: 1,
			fillOpacity: 0.9
		};
	}

	function yardColorForIndex(v) {
		if (v === null || v === undefined || v === '' || isNaN(parseFloat(v))) {
			return { fill: '#cbd5e1', stroke: '#64748b' };
		}
		var val = parseFloat(v);
		var t = Math.max(0, Math.min(1, val / 100));
		var stops = ['#dc2626', '#facc15', '#16a34a'];
		var n = stops.length - 1;
		var seg = t * n;
		var i = Math.floor(seg);
		if (i >= n) return { fill: stops[n], stroke: '#1e293b' };
		var u = seg - i;
		function hex(h) {
			h = h.replace('#', '');
			return { r: parseInt(h.substr(0, 2), 16), g: parseInt(h.substr(2, 2), 16), b: parseInt(h.substr(4, 2), 16) };
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
				var v = props[key];
				if (v === null || v === undefined || v === '') return;
				var n = parseFloat(v);
				if (isNaN(n) || n <= 0) return;
				sums[key] += n;
				counts[key] += 1;
			});
		});
		if (chart.datasets && chart.datasets[0]) {
			chart.datasets[0].data = keys.map(function (key) {
				return counts[key] > 0 ? Math.round((sums[key] / counts[key]) * 100) / 100 : 0;
			});
		}
		return chart;
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
					'</span></p>';
				html +=
					'<p class="wscosm-ct-stats"><strong>' +
					(cfg.i18n.osmObjects || '') +
					':</strong> ' +
					(typeof d.osmObjectsCount === 'number' ? d.osmObjectsCount : 0) +
					'</p>';
				html +=
					'<p class="wscosm-ct-stats"><strong>' +
					$('<div/>').text('Зданий OSM в базе').html() +
					':</strong> ' +
					(typeof d.osmBuildingsCount === 'number' ? d.osmBuildingsCount : 0) +
					'</p>';

				if (d.generateBufferYardsUrl) {
					var generateBtnId = 'wscosm-ct-generate-yards-' + d.cityId + '-' + Date.now();
					d.generateBufferYardsButtonId = generateBtnId;
					html +=
						'<p class="wscosm-ct-generate-actions" style="margin:0.75rem 0 0.25rem;">' +
						'<button type="button" class="button button-primary" id="' +
						generateBtnId +
						'">' +
						$('<div/>').text(cfg.i18n.generateBufferYards || '').html() +
						'</button> ' +
						'<span class="wscosm-ct-generate-status description" style="margin-left:0.35rem;"></span></p>';
					html +=
						'<div class="wscosm-ct-generate-progress-wrap" style="display:none;margin-top:0.35rem;max-width:560px;">' +
						'<progress class="wscosm-ct-buffer-progress" max="100" value="0"></progress>' +
						'<div class="wscosm-ct-buffer-progress-label description" style="margin-top:0.3rem;line-height:1.35;"></div></div>';
				}

				if (d.recalculateErgoUrl) {
					var recalcBtnId = 'wscosm-ct-recalc-ergo-' + d.cityId + '-' + Date.now();
					d.recalcErgoButtonId = recalcBtnId;
					html +=
						'<p class="wscosm-ct-ergo-actions" style="margin:0.75rem 0 0.25rem;">' +
						'<button type="button" class="button button-secondary" id="' +
						recalcBtnId +
						'">' +
						$('<div/>').text(cfg.i18n.recalcErgo || '').html() +
						'</button> ' +
						'<span class="wscosm-ct-recalc-status description" style="margin-left:0.35rem;"></span></p>';
				}

				var mapId = 'wscosm-ct-map-' + d.cityId;
				html += '<h4 class="wsp-section-title" style="margin-top:1rem;">' + (cfg.i18n.mapTitle || 'Map') + '</h4>';
				html += '<div id="' + mapId + '" class="wsp-minimap ergo-map-container" style="height:420px;background:#d4e6f1;margin-bottom:1rem;"></div>';
				html +=
					'<div class="wscosm-building-modal" id="wscosm-building-modal-' +
					d.cityId +
					'" hidden>' +
					'<div class="wscosm-building-modal__backdrop" data-modal-close="1"></div>' +
					'<div class="wscosm-building-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wscosm-building-modal-title-' +
					d.cityId +
					'">' +
					'<button type="button" class="wscosm-building-modal__close" aria-label="Close" data-modal-close="1">&times;</button>' +
					'<div class="wscosm-building-modal__body" id="wscosm-building-modal-body-' +
					d.cityId +
					'"></div></div></div>';

				var chartId = '';
				if (d.chart && d.chart.labels && d.chart.labels.length) {
					chartId = 'wscosm-ct-chart-' + d.cityId + '-' + Date.now();
					html += '<div class="wsp-chart-wrap">';
					if (d.chart.title) {
						html += '<h4 class="wsp-chart-title">' + $('<div/>').text(d.chart.title).html() + '</h4>';
					}
					html += '<div class="wsp-chart-canvas-wrap" style="position:relative;height:280px;"><canvas id="' + chartId + '"></canvas></div></div>';
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

	function initYardsMap(container, d) {
		if (typeof L === 'undefined') return;
		if (container._wscosmLeafletMap) {
			container._wscosmLeafletMap.remove();
			container._wscosmLeafletMap = null;
		}

		var Ltxt = cfg.i18n || {};
		var m = L.map(container, { zoomControl: true }).setView([d.lat, d.lng], d.zoom || 14);
		container._wscosmLeafletMap = m;

		L.tileLayer(d.tileUrl, { attribution: d.tileAttrib, maxZoom: 19, subdomains: 'abcd' }).addTo(m);

		var centerGroup = L.layerGroup();
		L.circleMarker([d.lat, d.lng], {
			radius: 9,
			fillColor: '#ef4444',
			color: '#fff',
			weight: 2,
			fillOpacity: 0.9
		}).addTo(centerGroup).bindPopup('<strong>' + (d.cityName || '') + '</strong>');

		var yardsGroup = L.featureGroup();
		var buildingsGroup = L.featureGroup();
		var objectsGroup = L.featureGroup();
		var zoneGroup = L.featureGroup();
		var zoneObjectsGroup = L.featureGroup();

		var scanCtx = {
			map: m,
			d: d,
			Ltxt: Ltxt,
			yardsGroup: yardsGroup,
			buildingsGroup: buildingsGroup,
			objectsGroup: objectsGroup,
			zoneGroup: zoneGroup,
			zoneObjectsGroup: zoneObjectsGroup,
			selectedBuilding: null,
			selectedBuildingLayer: null,
			zoneObjects: []
		};

		function refreshLayers() {
			if (zoneGroup.bringToFront) zoneGroup.bringToFront();
			if (zoneObjectsGroup.bringToFront) zoneObjectsGroup.bringToFront();
			if (buildingsGroup.bringToFront) buildingsGroup.bringToFront();
		}

		function getModal() {
			return document.getElementById('wscosm-building-modal-' + d.cityId);
		}

		function getModalBody() {
			return document.getElementById('wscosm-building-modal-body-' + d.cityId);
		}

		function closeBuildingModal() {
			var modal = getModal();
			if (!modal) return;
			modal.hidden = true;
			document.body.classList.remove('wscosm-modal-open');
		}

		function openBuildingModal() {
			var modal = getModal();
			if (!modal) return;
			modal.hidden = false;
			document.body.classList.add('wscosm-modal-open');
		}

		function setSelectedBuildingLayer(layer) {
			if (scanCtx.selectedBuildingLayer && scanCtx.selectedBuildingLayer.setStyle) {
				scanCtx.selectedBuildingLayer.setStyle(buildingStyle());
			}
			scanCtx.selectedBuildingLayer = layer || null;
			if (scanCtx.selectedBuildingLayer && scanCtx.selectedBuildingLayer.setStyle) {
				scanCtx.selectedBuildingLayer.setStyle(selectedBuildingStyle());
			}
		}

		function popupObjectListHtml(features) {
			var list = features || [];
			if (!list.length) {
				return '<p class="wsp-muted">' + $('<div/>').text(Ltxt.courtyardNoObjects || 'No objects').html() + '</p>';
			}
			var html = '<ul class="wscosm-house-list">';
			list.forEach(function (feat) {
				var p = (feat && feat.properties) || {};
				var title = p.name || p.wscosm_kind || 'OSM object';
				var sub = (p.wscosm_osm_el_type || '') + (p.wscosm_osm_id ? '/' + p.wscosm_osm_id : '');
				html += '<li><strong>' + $('<div/>').text(String(title)).html() + '</strong><br><span class="wsp-muted">' + $('<div/>').text(String(sub)).html() + '</span></li>';
			});
			html += '</ul>';
			return html;
		}

		function renderBuildingModal(isLoading) {
			var panel = getModalBody();
			if (!panel) return;
			var selected = scanCtx.selectedBuilding;
			var radius = d.courtyardBufferRadiusM || 35;
			if (!selected) {
				panel.innerHTML = '<div class="wscosm-house-empty">' + $('<div/>').text(Ltxt.courtyardNoBuilding || 'Select building on map').html() + '</div>';
				return;
			}
			var p = selected.properties || {};
			var title = p.name || selected.object_key || (selected.osm_type + '/' + selected.osm_id);
			var loadingHtml =
				'<div class="wscosm-building-modal__loading">' +
				$('<div/>').text(Ltxt.loading || 'Loading').html() +
				'</div>';
			panel.innerHTML =
				'<div class="wscosm-house-head"><h4 id="wscosm-building-modal-title-' + d.cityId + '">' + $('<div/>').text(Ltxt.courtyardTitle || 'Selected house').html() + '</h4></div>' +
				'<p><strong>' + $('<div/>').text(String(title)).html() + '</strong></p>' +
				'<p class="wsp-muted">' + $('<div/>').text(String(p.wscosm_kind || '')).html() + '</p>' +
				'<p class="wsp-muted">' + $('<div/>').text((Ltxt.courtyardRadius || 'Buffer radius') + ': ' + radius + ' м').html() + '</p>' +
				'<p><button type="button" class="button button-primary" id="wscosm-recalc-zone-btn">' + $('<div/>').text(Ltxt.courtyardRecalc || 'Recalculate zone').html() + '</button></p>' +
				(isLoading ? loadingHtml : '') +
				'<h5>' + $('<div/>').text(Ltxt.courtyardObjects || 'Objects in zone').html() + '</h5>' +
				popupObjectListHtml(scanCtx.zoneObjects);

			var btn = document.getElementById('wscosm-recalc-zone-btn');
			if (btn) {
				btn.addEventListener('click', function () {
					requestBufferZone(selected, true);
				});
			}
		}

		function drawZoneObjects(features) {
			zoneObjectsGroup.clearLayers();
			(features || []).forEach(function (feat) {
				if (!feat || !feat.geometry) return;
				var kind = (feat.properties && feat.properties.wscosm_kind) || 'other';
				var gj = L.geoJSON(feat, {
					style: function (f) {
						var t = f && f.geometry ? f.geometry.type : '';
						return objectStyle(kind, t);
					},
					pointToLayer: function (f, latlng) {
						return L.circleMarker(latlng, objectPointStyle(kind));
					},
					onEachFeature: function (f, layer) {
						var p = f.properties || {};
						var name = p.name || p.wscosm_kind || 'OSM object';
						layer.bindPopup('<div class="wsp-marker-popup-content"><strong>' + $('<div/>').text(String(name)).html() + '</strong></div>');
					}
				});
				gj.eachLayer(function (layer) {
					zoneObjectsGroup.addLayer(layer);
				});
			});
		}

		function requestBufferZone(buildingRef, fitToZone) {
			if (!d.buildingBufferZoneUrl || !buildingRef) return;
			renderBuildingModal(true);
			openBuildingModal();
			fetch(
				d.buildingBufferZoneUrl,
				restPostOptions({
					object_key: buildingRef.object_key || '',
					osm_type: buildingRef.osm_type || '',
					osm_id: buildingRef.osm_id || 0
				})
			)
				.then(function (r) {
					return r.json().then(function (body) {
						if (!r.ok) {
							var msg = (body && (body.message || body.code)) || (Ltxt.courtyardLoadError || 'Error');
							throw new Error(msg);
						}
						return body;
					});
				})
				.then(function (data) {
					scanCtx.selectedBuilding = {
						object_key: data && data.building ? data.building.object_key : buildingRef.object_key,
						osm_type: data && data.building ? data.building.osm_type : buildingRef.osm_type,
						osm_id: data && data.building ? data.building.osm_id : buildingRef.osm_id,
						properties: {
							name: data && data.building ? data.building.name : '',
							wscosm_kind: data && data.building ? data.building.kind : ''
						}
					};
					scanCtx.zoneObjects = (data && data.objects && data.objects.features) || [];

					zoneGroup.clearLayers();
					if (data && data.zone && data.zone.geometry) {
						var zgj = L.geoJSON(data.zone, {
							style: {
								color: '#1d4ed8',
								weight: 2,
								opacity: 1,
								fillColor: '#93c5fd',
								fillOpacity: 0.2,
								dashArray: '6 6'
							}
						});
						zgj.eachLayer(function (layer) {
							zoneGroup.addLayer(layer);
						});
						if (fitToZone && zoneGroup.getLayers().length > 0) {
							m.fitBounds(zoneGroup.getBounds(), { padding: [20, 20] });
						}
					}
					d.courtyardBufferRadiusM = data && data.radius_m ? data.radius_m : d.courtyardBufferRadiusM;
					drawZoneObjects(scanCtx.zoneObjects);
					renderBuildingModal(false);
					openBuildingModal();
					refreshLayers();
				})
				.catch(function (err) {
					window.alert((err && err.message) || (Ltxt.courtyardLoadError || 'Error'));
				});
		}

		function bindModalCloseHandlers() {
			var modal = getModal();
			if (!modal || modal._wscosmBound) return;
			modal._wscosmBound = true;
			modal.addEventListener('click', function (evt) {
				var target = evt.target;
				if (target && target.getAttribute && target.getAttribute('data-modal-close') === '1') {
					closeBuildingModal();
				}
			});
			document.addEventListener('keydown', function (evt) {
				if (evt.key === 'Escape' && !modal.hidden) {
					closeBuildingModal();
				}
			});
		}

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
					var yardAreaFeatures = yardFeatures.filter(function (feat) {
						return feat && feat.geometry && isPolygonGeometry(feat.geometry.type);
					});
					if (d.yardsCountElementId) {
						var countEl = document.getElementById(d.yardsCountElementId);
						if (countEl) countEl.textContent = String(yardAreaFeatures.length);
					}
					if (d.chartElementId && d.chart && d.chart.labels && d.chart.labels.length && window.WSPChart && typeof Chart !== 'undefined') {
						window.WSPChart.render(d.chartElementId, buildYardMeansChart(d.chart, yardFeatures));
					}
					if (yardAreaFeatures.length) {
						var ygj = L.geoJSON(
							{ type: 'FeatureCollection', features: yardAreaFeatures },
							{
							style: function (feat) {
								var v = feat.properties ? feat.properties.index : null;
								var c = yardColorForIndex(v);
								return { fillColor: c.fill, color: c.stroke, weight: 0.8, opacity: 1, fillOpacity: 0.55 };
							}
							}
						);
						ygj.eachLayer(function (layer) {
							yardsGroup.addLayer(layer);
						});
					}
					refreshLayers();
				});
		}

		function loadOsmLayer(forceLive) {
			if (!d.featuresUrl) return;
			var url = localViewportUrl(d.featuresUrl, m.getBounds(), !!forceLive);
			fetch(url, restFetchOptions())
				.then(function (r) {
					return r.ok ? r.json() : { features: [] };
				})
				.catch(function () {
					return { features: [] };
				})
				.then(function (fc) {
					buildingsGroup.clearLayers();
					objectsGroup.clearLayers();
					(fc.features || []).forEach(function (feat) {
						if (!feat || !feat.geometry) return;
						var p = feat.properties || {};
						var kind = p.wscosm_kind || 'other';
						var key = p.object_key || osmFeatureKey(feat);
						if (isBldgKind(kind) && kind !== 'bldg_part') {
							var gjb = L.geoJSON(feat, {
								style: buildingStyle,
								onEachFeature: function (f, layer) {
									layer.on('click', function () {
										var ref = {
											object_key: key || '',
											osm_type: p.wscosm_osm_el_type || '',
											osm_id: parseInt(p.wscosm_osm_id || 0, 10) || 0,
											properties: p
										};
										scanCtx.selectedBuilding = ref;
										setSelectedBuildingLayer(layer);
										renderBuildingModal(true);
										openBuildingModal();
										requestBufferZone(ref, true);
									});
								}
							});
							gjb.eachLayer(function (layer) {
								buildingsGroup.addLayer(layer);
							});
						} else {
							var gjo = L.geoJSON(feat, {
								style: function (f) {
									var t = f && f.geometry ? f.geometry.type : '';
									return objectStyle(kind, t);
								},
								pointToLayer: function (f, latlng) {
									return L.circleMarker(latlng, objectPointStyle(kind));
								}
							});
							gjo.eachLayer(function (layer) {
								objectsGroup.addLayer(layer);
							});
						}
					});
					refreshLayers();
					var fit = yardsGroup.getLayers().length ? yardsGroup.getBounds() : buildingsGroup.getBounds();
					if (fit && fit.isValid && fit.isValid()) {
						m.fitBounds(fit, { padding: [18, 18] });
					}
				});
		}

		function addScanControl() {
			if (!d.canScanOsm || !d.featuresUrl) return;
			var ScanCtrl = L.Control.extend({
				options: { position: 'topleft' },
				onAdd: function () {
					var wrap = L.DomUtil.create('div', 'leaflet-bar wscosm-scan-wrap');
					var btn = L.DomUtil.create('button', 'wscosm-scan-btn', wrap);
					btn.type = 'button';
					btn.appendChild(document.createTextNode(Ltxt.scanOsm || 'Scan'));
					btn.title = Ltxt.scanOsmHint || '';
					L.DomEvent.disableClickPropagation(wrap);
					L.DomEvent.on(btn, 'click', function (e) {
						L.DomEvent.stop(e);
						btn.disabled = true;
						btn.classList.add('is-busy');
						loadOsmLayer(true);
						window.setTimeout(function () {
							btn.disabled = false;
							btn.classList.remove('is-busy');
						}, 1200);
					});
					return wrap;
				}
			});
			new ScanCtrl().addTo(m);
		}

		if (d.recalculateErgoUrl && d.recalcErgoButtonId) {
			var recalcBtn = document.getElementById(d.recalcErgoButtonId);
			var recalcWrap = recalcBtn ? recalcBtn.closest('.wscosm-ct-ergo-actions') : null;
			var recalcStatus = recalcWrap ? recalcWrap.querySelector('.wscosm-ct-recalc-status') : null;
			if (recalcBtn) {
				recalcBtn.addEventListener('click', function () {
					recalcBtn.disabled = true;
					if (recalcStatus) recalcStatus.textContent = cfg.i18n.recalcErgoWorking || '';
					fetch(d.recalculateErgoUrl, restPostOptions({}))
						.then(function (r) {
							return r.json().then(function (body) {
								return { ok: r.ok, body: body };
							});
						})
						.then(function (res) {
							if (!res.ok) {
								var msg =
									(res.body && res.body.message) ||
									(res.body && res.body.data && res.body.data.message) ||
									(cfg.i18n.recalcErgoError || '');
								if (recalcStatus) recalcStatus.textContent = msg;
								return;
							}
							var n = res.body && typeof res.body.processed === 'number' ? res.body.processed : 0;
							if (recalcStatus) recalcStatus.textContent = (cfg.i18n.recalcErgoDone || '') + ': ' + String(n);
							loadYardsLayer();
						})
						.catch(function () {
							if (recalcStatus) recalcStatus.textContent = cfg.i18n.recalcErgoError || '';
						})
						.finally(function () {
							recalcBtn.disabled = false;
						});
				});
			}
		}

		if (d.generateBufferYardsUrl && d.generateBufferYardsButtonId) {
			var generateBtn = document.getElementById(d.generateBufferYardsButtonId);
			var generateWrap = generateBtn ? generateBtn.closest('.wscosm-ct-generate-actions') : null;
			var generateStatus = generateWrap ? generateWrap.querySelector('.wscosm-ct-generate-status') : null;
			if (generateBtn) {
				generateBtn.addEventListener('click', function () {
					var detailRoot = generateWrap ? generateWrap.closest('#wscosm-ct-detail') : null;
					var nw =
						generateWrap && generateWrap.nextElementSibling &&
						generateWrap.nextElementSibling.classList &&
						generateWrap.nextElementSibling.classList.contains(
							'wscosm-ct-generate-progress-wrap'
						)
							? generateWrap.nextElementSibling
							: null;
					var progressWrap =
						nw || (detailRoot ? detailRoot.querySelector('.wscosm-ct-generate-progress-wrap') : null);
					var progressEl = progressWrap ? progressWrap.querySelector('progress.wscosm-ct-buffer-progress') : null;
					var progressLabelEl = progressWrap ? progressWrap.querySelector('.wscosm-ct-buffer-progress-label') : null;
					var pid = makeBufferProgressId();
					var pollTimer = null;
					var pollUrl =
						cfg.scanProgressUrl && pid
							? cfg.scanProgressUrl +
								(cfg.scanProgressUrl.indexOf('?') >= 0 ? '&' : '?') +
								'progress_id=' +
								encodeURIComponent(pid)
							: '';

					function applyScanProgress(payload) {
						if (!payload) return;
						if (!progressEl || !progressLabelEl) return;
						var phase = String(payload.phase || '');
						var total = parseInt(payload.total, 10);
						total = isNaN(total) ? 0 : total;
						var cur = parseInt(payload.current, 10);
						cur = isNaN(cur) ? 0 : cur;
						var saved = parseInt(payload.saved, 10);
						saved = isNaN(saved) ? 0 : saved;
						var Ltxt = cfg.i18n || {};
						var pct = 0;
						var lbl = '';

						if (phase === 'prepare') {
							pct = 3;
							lbl = Ltxt.bufferYardsProgressPrepare || '';
						} else if (phase === 'buffer') {
							pct =
								total > 0 ? Math.min(44, Math.round((44 * cur) / Math.max(total, 1))) : 10;
							lbl =
								(Ltxt.bufferYardsProgressBuffer || '') +
								' — ' +
								bufferProgressCountsLabel(Ltxt.bufferYardsProgressCounts, cur, total);
						} else if (phase === 'saving') {
							pct =
								total > 0
									? 44 + Math.min(55, Math.round((55 * saved) / Math.max(total, 1)))
									: 55;
							lbl =
								(Ltxt.bufferYardsProgressSaving || '') +
								' — ' +
								bufferProgressCountsLabel(Ltxt.bufferYardsProgressCounts, saved, total);
						} else if (phase === 'done') {
							pct = 100;
							lbl = Ltxt.bufferYardsProgressDone || '';
						} else if (phase === 'error') {
							pct = 0;
							lbl = payload.message || Ltxt.generateBufferYardsError || '';
						} else {
							pct = 1;
							lbl = Ltxt.generateBufferYardsWorking || '';
						}
						progressEl.value = Math.min(100, Math.max(0, pct));
						progressLabelEl.textContent = lbl;
					}

					generateBtn.disabled = true;
					if (generateStatus) generateStatus.textContent = cfg.i18n.generateBufferYardsWorking || '';

					if (progressWrap && progressEl && progressLabelEl) {
						progressWrap.style.display = 'block';
						progressWrap.setAttribute('aria-busy', 'true');
						progressEl.value = 0;
						applyScanProgress({
							phase: 'prepare',
							total: 0,
							current: 0,
							saved: 0
						});
						if (!pollUrl) {
							progressEl.removeAttribute('value');
							var L0 = cfg.i18n || {};
							progressLabelEl.textContent =
								(L0.bufferYardsProgressPrepare || '') ||
								L0.generateBufferYardsWorking ||
								'';
							progressWrap.classList.add('wscosm-ct-generate-progress-wrap--pulse');
						}
					}

					if (pollUrl) {
						pollTimer = window.setInterval(function () {
							fetch(pollUrl, restFetchOptions())
								.then(function (r) {
									return r.ok ? r.json() : null;
								})
								.then(function (body) {
									if (body) applyScanProgress(body);
								})
								.catch(function () {});
						}, 450);
					}

					var postPayload = { replace_existing: true };
					if (pid && pid.length === 32) postPayload.progress_id = pid;

					fetch(d.generateBufferYardsUrl, restPostOptions(postPayload))
						.then(function (r) {
							return r.json().then(function (body) {
								return { ok: r.ok, body: body };
							});
						})
						.then(function (res) {
							if (!res.ok) {
								var msg =
									(res.body && res.body.message) ||
									(res.body && res.body.data && res.body.data.message) ||
									(cfg.i18n.generateBufferYardsError || '');
								if (generateStatus) generateStatus.textContent = msg;
								if (progressWrap && pid) applyScanProgress({ phase: 'error', message: msg });
								return;
							}
							var saved = res.body && typeof res.body.saved === 'number' ? res.body.saved : 0;
							if (generateStatus) {
								generateStatus.textContent =
									(cfg.i18n.generateBufferYardsDone || '') + ': ' + String(saved);
							}
							if (progressEl && cfg.i18n) {
								applyScanProgress({
									phase: 'done',
									total:
										res.body && typeof res.body.generated_features === 'number'
											? res.body.generated_features
											: 0,
									current:
										res.body && typeof res.body.generated_features === 'number'
											? res.body.generated_features
											: 0,
									saved: saved
								});
							}
							loadYardsLayer();
						})
						.catch(function () {
							if (generateStatus)
								generateStatus.textContent = cfg.i18n.generateBufferYardsError || '';
							if (progressWrap && pid)
								applyScanProgress({
									phase: 'error',
									message: cfg.i18n.generateBufferYardsError || ''
								});
						})
						.finally(function () {
							if (pollTimer) window.clearInterval(pollTimer);
							window.setTimeout(function () {
								if (progressWrap) {
									progressWrap.style.display = 'none';
									progressWrap.classList.remove('wscosm-ct-generate-progress-wrap--pulse');
									progressWrap.removeAttribute('aria-busy');
								}
							}, 900);
							generateBtn.disabled = false;
						});
				});
			}
		}

		centerGroup.addTo(m);
		yardsGroup.addTo(m);
		buildingsGroup.addTo(m);
		objectsGroup.addTo(m);
		zoneGroup.addTo(m);
		zoneObjectsGroup.addTo(m);
		bindModalCloseHandlers();
		refreshLayers();
		addScanControl();
		loadYardsLayer();
		loadOsmLayer(false);
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
		var panels = document.querySelectorAll('.wscosm-country-tab-shell');
		panels.forEach(function (shell) {
			var iso2 = shell.getAttribute('data-iso2') || '';
			bindCountryTab($(shell).closest('.wsp-tab-panel, body'), iso2);
		});
	}

	document.addEventListener('DOMContentLoaded', bindExistingCountryTabs);

	$(document).on('wsp:tab:loaded', function (_evt, tabId, iso2, $panel) {
		if (tabId !== 'courtyard_osm') return;
		bindCountryTab($panel, iso2);
	});
})(jQuery);
