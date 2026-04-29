=== WorldStat — Courtyard OSM Map ===
Contributors: ergonosphera
Tags: world statistics, openstreetmap, ergonomics, leaflet, cities
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.7
License: GPLv2 or later

Расширение World Statistics Platform: на странице города показывает карту придомовой среды с полигонами из плагина эргономики (если есть данные) и объектами OpenStreetMap (скамейки, фонари, пешеходные пути и др.).

== Dependencies ==

* World Statistics Platform
* WorldStat Cities
* WorldStat Ergonomics (для REST полигонов придомовых)

== OpenStreetMap & Overpass ==

Данные OSM загружаются через публичный Overpass API (по умолчанию overpass-api.de). Запросы выполняются **с сервера** WordPress и кэшируются (transients) на 48 часов по умолчанию, чтобы снизить нагрузку на Overpass.

Уважайте политику использования выбранного экземпляра Overpass; при высокой нагрузке можно переопределить URL фильтром `wscosm_overpass_interpreter_url`.

На карте отображается атрибуция © OpenStreetMap и CARTO (для тайлов Carto Light).

== Options (get_option) ==

* `wscosm_radius_km` — радиус выборки вокруг центра города (км), по умолчанию 1.2, максимум ограничен фильтром `wscosm_max_radius_km` (5).
* `wscosm_cache_ttl_hours` — TTL кэша Overpass в часах (по умолчанию 48).

== Disclaimer ==

Слои OSM носят справочный характер и не заменяют методику оценки эргономичности базового плагина.
