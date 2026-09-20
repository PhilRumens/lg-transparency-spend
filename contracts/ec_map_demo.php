<?php
/**
 * ec_map_demo.php — THROWAWAY DEMO of an open-map view for contract expiry
 * clusters. Leaflet + OpenStreetMap raster tiles (no API key). One marker per
 * council positioned by ct_la_geo centroid; councils in the same cluster are
 * joined by lines and share a colour. Not linked from nav — delete after review.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/cluster_lib.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// ── Filters (GET) — mirror the live expiry_clusters.php filter bar ──────────
$group_opts   = ['proximity' => 'Proximity (distance)', 'sa' => 'Strategic Authority'];
$radius_opts  = [15 => '15 km (same conurbation)', 25 => '25 km', 40 => '40 km (sub-region)', 60 => '60 km', 100 => '100 km (wide)', 2000 => 'UK-wide (any distance)'];
$window_opts  = [3 => 'within 3 months', 6 => 'within 6 months', 9 => 'within 9 months', 12 => 'within 12 months', 24 => 'within 24 months'];
$horizon_opts = [3 => 'next 3 months', 6 => 'next 6 months', 12 => 'next 12 months', 24 => 'next 2 years', 36 => 'next 3 years', 60 => 'next 5 years', 0 => 'any future date'];
$minc_opts    = [2 => '2+', 3 => '3+', 4 => '4+', 5 => '5+', 10 => '10+'];
$n_opts       = [20 => 'top 20', 40 => 'top 40', 60 => 'top 60', 100 => 'top 100', 9999 => 'all'];

$group   = (string)($_GET['group'] ?? 'proximity'); if (!isset($group_opts[$group])) $group = 'proximity';
$radius  = (int)($_GET['radius'] ?? 40);   if (!isset($radius_opts[$radius]))   $radius = 40;
$window  = (int)($_GET['window'] ?? 6);    if (!isset($window_opts[$window]))   $window = 6;
$horizon = (int)($_GET['horizon'] ?? 24);  if (!isset($horizon_opts[$horizon])) $horizon = 24;
$minc    = (int)($_GET['minc']   ?? 3);    if (!isset($minc_opts[$minc]))       $minc = 3;
$ncap    = (int)($_GET['n']      ?? 40);   if (!isset($n_opts[$ncap]))          $ncap = 40;

$clusters = find_expiry_clusters($pdo, [
    'group_by'       => $group,
    'radius_km'      => $radius,
    'window_months'  => $window,
    'horizon_months' => $horizon,
    'min_councils'   => $minc,
]);
$total_found = count($clusters);

// Cap to the top N by score so the map isn't a hairball.
$clusters = array_slice($clusters, 0, $ncap);

// Colour palette (GOV.UK-ish, high-contrast, colourblind-friendly-ish).
$palette = ['#1d70b8','#d4351c','#00703c','#912b88','#f47738','#28a197','#5694ca','#b58840','#4c2c92','#85994b'];

function fmt_gbp_d(float $v): string {
    if ($v >= 1e6) return '£' . number_format($v / 1e6, 1) . 'M';
    if ($v >= 1e3) return '£' . number_format($v / 1e3, 0) . 'k';
    return '£' . number_format($v);
}

// Build the JS payload: one entry per cluster with its member councils' coords.
$map_clusters = [];
foreach ($clusters as $i => $c) {
    $members = [];
    foreach ($c['councils'] as $m) {
        $members[] = [
            'council' => $m['council'],
            'lat'     => (float)$m['lat'],
            'lon'     => (float)$m['lon'],
            'end'     => $m['end_date'],
            'value'   => (float)$m['value'],
            'value_f' => fmt_gbp_d((float)$m['value']),
        ];
    }
    $map_clusters[] = [
        'i'        => $i,
        'product'  => $c['product_name'] ?: 'Unnamed product',
        'supplier' => $c['product_supplier'] ?? '',
        'color'    => $palette[$i % count($palette)],
        'n'        => (int)$c['n_councils'],
        'lo'       => $c['earliest_end'],
        'hi'       => $c['latest_end'],
        'total_f'  => fmt_gbp_d((float)$c['total_value']),
        'members'  => $members,
    ];
}

layout_head('Expiry clusters — MAP DEMO', '');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<style>
  #ec-map { height: 640px; border:1px solid #b1b4b6; margin:0 0 12px; }
  .ec-map-legend { max-height:260px; overflow:auto; font-size:.85rem; border:1px solid #b1b4b6; padding:10px 12px; }
  .ec-map-legend h3 { font-size:.9rem; margin:0 0 8px; }
  .ec-leg-row { display:flex; align-items:center; gap:8px; margin:0 0 5px; cursor:pointer; padding:2px 3px; }
  .ec-leg-row:hover { background:#f3f2f1; }
  .ec-leg-sw { width:14px; height:14px; border-radius:50%; flex:none; border:2px solid #fff; box-shadow:0 0 0 1px #b1b4b6; }
  .ec-leg-txt { line-height:1.3; }
  .ec-leg-txt small { color:#505a5f; }
  .ec-demo-note { background:#fff3cd; border-left:5px solid #f47738; padding:10px 14px; margin:0 0 16px; font-size:.9rem; }
  .leaflet-popup-content { font-size:.85rem; line-height:1.4; }
  .leaflet-popup-content strong { display:block; margin-bottom:3px; }
  /* Filter bar — mirrors expiry_clusters.php */
  .ec-filters { display:flex; align-items:flex-end; gap:14px; margin:0 0 16px; flex-wrap:wrap; }
  .ec-filters .govuk-label { margin:0 0 2px; font-weight:700; font-size:.8rem }
  .ec-filters select { height:36px; padding:2px 8px }
  @media (min-width:641px) {
    .ec-filters { flex-wrap:nowrap; }
    .ec-filters > div { flex:1 1 0; min-width:0; }
    .ec-filters select { width:100%; }
  }
  .ec-count { color:#505a5f; font-size:.9rem; margin:0 0 12px }
</style>

<h1 class="govuk-heading-l">Contract expiry clusters — map demo</h1>
<div class="ec-demo-note">
  <strong>Throwaway demo.</strong> Leaflet + OpenStreetMap tiles (open, no API key).
  Each colour is one cluster; lines join councils sharing the same product + expiry window; dot size ∝ contract value.
  Click a marker for detail, or a legend row to zoom. Not linked from the nav.
</div>

<form method="get" class="ec-filters">
  <div>
    <label class="govuk-label" for="group">Group by</label>
    <select class="govuk-select" id="group" name="group" onchange="this.form.submit()">
      <?php foreach ($group_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $group ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div<?= $group === 'sa' ? ' style="display:none"' : '' ?>>
    <label class="govuk-label" for="radius">Proximity</label>
    <select class="govuk-select" id="radius" name="radius" onchange="this.form.submit()">
      <?php foreach ($radius_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $radius ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="horizon">Expires within</label>
    <select class="govuk-select" id="horizon" name="horizon" onchange="this.form.submit()">
      <?php foreach ($horizon_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $horizon ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="window">Co-termination spread</label>
    <select class="govuk-select" id="window" name="window" onchange="this.form.submit()">
      <?php foreach ($window_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $window ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="minc">Min councils</label>
    <select class="govuk-select" id="minc" name="minc" onchange="this.form.submit()">
      <?php foreach ($minc_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $minc ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="n">Show</label>
    <select class="govuk-select" id="n" name="n" onchange="this.form.submit()">
      <?php foreach ($n_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $ncap ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="govuk-button" style="margin:0">Apply</button></noscript>
</form>

<p class="ec-count"><?= $total_found ?> cluster<?= $total_found === 1 ? '' : 's' ?> found
  (<?= $group === 'sa' ? 'by Strategic Authority' : ($radius >= 2000 ? 'UK-wide' : 'radius ' . $radius . ' km') ?>,
  expiring <?= $horizon === 0 ? 'any future date' : 'within ' . $horizon . ' months' ?>,
  co-terminating within <?= $window ?> months, <?= $minc ?>+ councils)<?php if ($total_found > count($map_clusters)): ?>
  — mapping the top <strong><?= count($map_clusters) ?></strong> by score<?php endif; ?>.</p>

<?php if (!$map_clusters): ?>
  <p class="govuk-body">No clusters match these filters. Try a wider radius, a longer horizon, a looser spread, or a lower council threshold.</p>
<?php else: ?>
<div class="govuk-grid-row">
  <div class="govuk-grid-column-two-thirds">
    <div id="ec-map"></div>
  </div>
  <div class="govuk-grid-column-one-third">
    <div class="ec-map-legend">
      <h3><?= count($map_clusters) ?> clusters</h3>
      <div id="ec-legend"></div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function(){
  var CLUSTERS = <?= json_encode($map_clusters, JSON_UNESCAPED_UNICODE) ?>;

  var map = L.map('ec-map', { scrollWheelZoom: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  var allLatLng = [];
  var layersByCluster = {};

  // radius by value within a cluster: sqrt scale, 5..16px
  function rOf(v, maxv){ if(maxv<=0) return 7; return 5 + 11*Math.sqrt(Math.max(0,v)/maxv); }

  CLUSTERS.forEach(function(cl){
    var grp = L.layerGroup().addTo(map);
    layersByCluster[cl.i] = grp;
    var maxv = 0; cl.members.forEach(function(m){ if(m.value>maxv) maxv=m.value; });

    // lines: connect every member to the cluster centroid (star) — readable
    var clat=0, clon=0; cl.members.forEach(function(m){ clat+=m.lat; clon+=m.lon; });
    clat/=cl.members.length; clon/=cl.members.length;

    cl.members.forEach(function(m){
      L.polyline([[clat,clon],[m.lat,m.lon]], {color:cl.color, weight:1.5, opacity:.5}).addTo(grp);
    });

    cl.members.forEach(function(m){
      allLatLng.push([m.lat, m.lon]);
      var mk = L.circleMarker([m.lat, m.lon], {
        radius: rOf(m.value, maxv),
        color:'#fff', weight:1.5, fillColor: cl.color, fillOpacity:.85
      }).addTo(grp);
      mk.bindPopup(
        '<strong>'+m.council+'</strong>'+
        cl.product + (cl.supplier ? ' — '+cl.supplier : '') + '<br>'+
        'Expires '+m.end+'<br>'+
        'Value '+m.value_f
      );
    });
  });

  if (allLatLng.length) map.fitBounds(allLatLng, {padding:[30,30]});
  else map.setView([54.0, -2.5], 6);

  // legend
  var leg = document.getElementById('ec-legend');
  CLUSTERS.forEach(function(cl){
    var row = document.createElement('div');
    row.className = 'ec-leg-row';
    row.innerHTML =
      '<span class="ec-leg-sw" style="background:'+cl.color+'"></span>'+
      '<span class="ec-leg-txt">'+cl.product+(cl.supplier?' <small>'+cl.supplier+'</small>':'')+
      '<br><small>'+cl.n+' councils · '+cl.lo+(cl.lo!==cl.hi?'–'+cl.hi:'')+' · '+cl.total_f+'</small></span>';
    row.addEventListener('click', function(){
      var pts = cl.members.map(function(m){ return [m.lat,m.lon]; });
      if (pts.length) map.fitBounds(pts, {padding:[50,50], maxZoom:10});
    });
    leg.appendChild(row);
  });
})();
</script>
<?php endif; ?>
<?php layout_foot(true); ?>
