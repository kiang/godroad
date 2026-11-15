#!/usr/bin/env python3
"""
Calculate road coverage for each Tainan district and generate an HTML report.

Usage: python3 scripts/calculate_coverage.py
Output: docs/report-202611.html
"""

import json
import glob
import os
import re
from datetime import datetime
from shapely.geometry import shape, LineString
from shapely.ops import unary_union
import pyproj

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TAINAN_DIR = "/home/kiang/public_html/address/docs/output/tainan"
DATA_JS = "/home/kiang/public_html/address/docs/data.js"
PATHS_GLOB = os.path.join(BASE_DIR, "docs", "2026-11*")
OUTPUT_HTML = os.path.join(BASE_DIR, "docs", "report-202611.html")
GAP_THRESHOLD_SEC = 600  # 10 minutes — gaps longer than this are idle time

geod = pyproj.Geod(ellps="WGS84")


def load_district_names(data_js_path):
    with open(data_js_path, encoding="utf-8") as f:
        content = f.read()
    match = re.search(r'const districtNames\s*=\s*(\{.*?\});', content)
    if match:
        return json.loads(match.group(1))
    return {}


def geodesic_length_m(coords):
    total = 0.0
    for i in range(len(coords) - 1):
        lng1, lat1 = coords[i]
        lng2, lat2 = coords[i + 1]
        _, _, dist = geod.inv(lng1, lat1, lng2, lat2)
        total += dist
    return total


def load_district(geojson_path):
    with open(geojson_path) as f:
        data = json.load(f)
    lines = []
    total_km = 0.0
    for feat in data["features"]:
        geom = shape(feat["geometry"])
        lines.append(geom)
        total_km += feat["properties"].get("km", 0)
    multi = unary_union(lines)
    polygon = multi.convex_hull.buffer(0.001)
    name = os.path.basename(geojson_path).replace(".geojson", "")
    return polygon, total_km, name, multi


def load_gps_paths(paths_glob):
    all_paths = []
    all_timestamps = []
    date_dirs = sorted(glob.glob(paths_glob))
    for date_dir in date_dirs:
        if not os.path.isdir(date_dir):
            continue
        for json_file in sorted(glob.glob(os.path.join(date_dir, "*.json"))):
            try:
                with open(json_file) as f:
                    entries = json.load(f)
                for entry in entries:
                    gps_points = entry.get("GPS", [])
                    if len(gps_points) >= 2:
                        coords = [(p["lng"], p["lat"]) for p in gps_points
                                  if p.get("lat") and p.get("lng")]
                        if len(coords) >= 2:
                            all_paths.append(coords)
                    t = entry.get("gps_time")
                    if t:
                        try:
                            all_timestamps.append(
                                datetime.strptime(t, "%Y-%m-%d %H:%M:%S"))
                        except ValueError:
                            pass
            except (json.JSONDecodeError, KeyError):
                continue
    return all_paths, all_timestamps


def calculate_active_hours(timestamps):
    if len(timestamps) < 2:
        return 0.0, 0
    ts = sorted(timestamps)
    active_seconds = 0.0
    session_count = 1
    for i in range(1, len(ts)):
        gap = (ts[i] - ts[i - 1]).total_seconds()
        if gap <= GAP_THRESHOLD_SEC:
            active_seconds += gap
        else:
            session_count += 1
    return active_seconds / 3600, session_count


def geom_length_m(geom):
    if geom.is_empty:
        return 0.0
    if geom.geom_type == "LineString":
        return geodesic_length_m(list(geom.coords))
    elif geom.geom_type == "MultiLineString":
        return sum(geodesic_length_m(list(line.coords)) for line in geom.geoms)
    elif geom.geom_type == "GeometryCollection":
        total = 0.0
        for g in geom.geoms:
            if g.geom_type in ("LineString", "MultiLineString"):
                total += geom_length_m(g)
        return total
    return 0.0


def calculate_coverage(polygon, road_lines, gps_paths):
    road_in_polygon = road_lines.intersection(polygon)
    if road_in_polygon.is_empty:
        return 0.0, 0.0

    gps_in_polygon = []
    for coords in gps_paths:
        line = LineString(coords)
        if polygon.intersects(line):
            clipped = line.intersection(polygon)
            if not clipped.is_empty:
                gps_in_polygon.append(clipped)

    if not gps_in_polygon:
        return 0.0, 0.0

    gps_buffer = unary_union([g.buffer(0.000135) for g in gps_in_polygon])
    covered = road_in_polygon.intersection(gps_buffer)

    total_road_m = geom_length_m(road_in_polygon)
    covered_m = geom_length_m(covered)
    return total_road_m, covered_m


def generate_html(results, total_traces, generated_at,
                   active_hours=0, session_count=0):
    total_road = sum(r["total_road_km"] for r in results)
    total_covered = sum(r["covered_m"] for r in results) / 1000
    measured_road_km = sum(r["measured_road_m"] for r in results) / 1000
    overall_pct = (total_covered / measured_road_km * 100) if measured_road_km > 0 else 0
    zero_count = sum(1 for r in results if r["coverage_pct"] == 0)
    hours_int = int(active_hours)
    minutes_rem = int((active_hours - hours_int) * 60)

    data_js_items = []
    for r in results:
        data_js_items.append(
            f'  {{name:"{r["name"]}",road:{r["total_road_km"]:.1f},'
            f'covered:{r["covered_m"]/1000:.2f},pct:{r["coverage_pct"]:.1f}}}'
        )
    data_js = "[\n" + ",\n".join(data_js_items) + "\n]"

    html = f"""<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>掃街覆蓋率</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700;900&family=Space+Grotesk:wght@400;500;700&display=swap">
<style>
:root {{
  --bg: #fafaf8;
  --surface: #ffffff;
  --text: #1a1917;
  --text-2: #5c5b56;
  --text-3: #8a8983;
  --border: #e6e5e0;
  --accent: #2a78d6;
  --accent-light: rgba(42,120,214,0.08);
  --bar-track: #edece8;
  --critical: #d03b3b;
  --critical-bg: rgba(208,59,59,0.06);
  --gridline: #e6e5e0;
}}
@media (prefers-color-scheme: dark) {{
  :root:not([data-theme="light"]) {{
    --bg: #111110;
    --surface: #1c1c1a;
    --text: #eeedea;
    --text-2: #b5b4ae;
    --text-3: #7a7974;
    --border: #2e2e2b;
    --accent: #3987e5;
    --accent-light: rgba(57,135,229,0.10);
    --bar-track: #282826;
    --critical: #e66767;
    --critical-bg: rgba(230,103,103,0.08);
    --gridline: #2e2e2b;
    color-scheme: dark;
  }}
}}
:root[data-theme="dark"] {{
  --bg: #111110;
  --surface: #1c1c1a;
  --text: #eeedea;
  --text-2: #b5b4ae;
  --text-3: #7a7974;
  --border: #2e2e2b;
  --accent: #3987e5;
  --accent-light: rgba(57,135,229,0.10);
  --bar-track: #282826;
  --critical: #e66767;
  --critical-bg: rgba(230,103,103,0.08);
  --gridline: #2e2e2b;
  color-scheme: dark;
}}
* {{ margin: 0; padding: 0; box-sizing: border-box; }}
body {{
  background: var(--bg);
  color: var(--text);
  font-family: 'Noto Sans TC', system-ui, sans-serif;
  line-height: 1.65;
  padding-left: max(16px, env(safe-area-inset-left, 0px));
  padding-right: max(16px, env(safe-area-inset-right, 0px));
  padding-block: 0;
}}
.page {{ max-width: 720px; margin: 0 auto; padding: 48px 0 64px; }}
.hero {{ padding: 0 0 40px; border-bottom: 1px solid var(--border); }}
.hero-kicker {{
  font-family: 'Space Grotesk', sans-serif;
  font-size: 0.75rem; font-weight: 500;
  letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--text-3); margin-bottom: 12px;
}}
.hero h1 {{
  font-size: clamp(1.75rem, 5vw, 2.5rem);
  font-weight: 900; line-height: 1.2;
  text-wrap: balance; margin-bottom: 12px;
}}
.hero h1 em {{ font-style: normal; color: var(--critical); }}
.hero-sub {{
  font-size: 1.05rem; color: var(--text-2);
  max-width: 560px; line-height: 1.7;
}}
.numbers {{
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 1px; background: var(--border);
  border-radius: 10px; overflow: hidden; margin: 32px 0;
}}
.num-card {{
  background: var(--surface); padding: 20px;
  display: flex; flex-direction: column; gap: 4px;
}}
.num-label {{ font-size: 0.75rem; color: var(--text-3); }}
.num-value {{
  font-family: 'Space Grotesk', sans-serif;
  font-size: 2rem; font-weight: 700; line-height: 1.15;
}}
.num-value .unit {{ font-size: 0.875rem; font-weight: 400; color: var(--text-3); margin-left: 2px; }}
.num-value.critical {{ color: var(--critical); }}
.num-note {{ font-size: 0.75rem; color: var(--text-3); }}
.context {{
  background: var(--critical-bg);
  border-left: 3px solid var(--critical);
  padding: 20px 24px; border-radius: 0 8px 8px 0;
  margin: 32px 0; font-size: 0.9rem; line-height: 1.75;
}}
.context strong {{ font-weight: 700; }}
.section-head {{
  padding: 40px 0 16px;
  border-bottom: 1px solid var(--border); margin-bottom: 24px;
}}
.section-head h2 {{ font-size: 1.125rem; font-weight: 700; }}
.section-head p {{ font-size: 0.8rem; color: var(--text-3); margin-top: 4px; }}
.chart-container {{ margin-bottom: 40px; }}
.bar-row {{
  display: grid; grid-template-columns: 72px 1fr 56px;
  align-items: center; gap: 12px; padding: 5px 0;
  border-bottom: 1px solid var(--gridline); position: relative;
}}
.bar-row:last-child {{ border-bottom: none; }}
.bar-row:hover {{ background: var(--accent-light); }}
.bar-name {{ font-size: 0.8rem; white-space: nowrap; text-align: right; }}
.bar-track {{
  height: 16px; background: var(--bar-track);
  border-radius: 2px; overflow: hidden;
}}
.bar-fill {{
  height: 100%; background: var(--accent);
  border-radius: 2px 4px 4px 2px;
  transition: width 0.4s ease-out;
}}
.bar-fill.zero {{ background: transparent; }}
.bar-pct {{
  font-family: 'Space Grotesk', sans-serif;
  font-size: 0.8rem; font-variant-numeric: tabular-nums;
  text-align: right; color: var(--text-2);
}}
.bar-pct.zero {{ color: var(--text-3); }}
.tooltip {{
  display: none; position: absolute; z-index: 5;
  left: 84px; top: -36px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 6px; padding: 6px 10px;
  font-size: 0.75rem; white-space: nowrap;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08); pointer-events: none;
}}
.bar-row:hover .tooltip {{ display: block; }}
.detail-table {{
  width: 100%; border-collapse: collapse;
  font-size: 0.8rem; margin-bottom: 40px;
}}
.detail-table th {{
  text-align: left; padding: 8px 10px;
  border-bottom: 2px solid var(--border);
  font-weight: 600; font-size: 0.75rem;
  color: var(--text-3); letter-spacing: 0.02em; white-space: nowrap;
}}
.detail-table th.r {{ text-align: right; }}
.detail-table td {{
  padding: 7px 10px; border-bottom: 1px solid var(--gridline);
}}
.detail-table td.r {{
  text-align: right; font-family: 'Space Grotesk', sans-serif;
  font-variant-numeric: tabular-nums;
}}
.detail-table td.zero {{ color: var(--text-3); }}
.detail-table tbody tr:hover {{ background: var(--accent-light); }}
.detail-table tfoot td {{
  font-weight: 700; border-top: 2px solid var(--border); border-bottom: none;
}}
.mini-bar {{
  width: 60px; height: 6px; background: var(--bar-track);
  border-radius: 3px; display: inline-block;
  vertical-align: middle; overflow: hidden;
}}
.mini-fill {{ height: 100%; background: var(--accent); border-radius: 3px; }}
.footer {{
  padding-top: 32px; border-top: 1px solid var(--border);
  font-size: 0.75rem; color: var(--text-3); line-height: 1.6;
  display: flex; flex-direction: column; gap: 4px;
}}
.toggle-wrap {{ display: flex; justify-content: flex-end; margin-bottom: 8px; }}
.toggle-btn {{
  font-family: inherit; font-size: 0.75rem; color: var(--accent);
  background: none; border: 1px solid var(--border);
  border-radius: 4px; padding: 4px 10px; cursor: pointer;
}}
.toggle-btn:hover {{ background: var(--accent-light); }}
@media (max-width: 520px) {{
  .bar-row {{ grid-template-columns: 56px 1fr 48px; gap: 8px; }}
  .num-value {{ font-size: 1.5rem; }}
  .detail-table {{ font-size: 0.75rem; }}
  .detail-table th, .detail-table td {{ padding: 5px 6px; }}
}}
</style>
</head>
<body>
<div class="page">
  <div class="hero">
    <div class="hero-kicker">2026 年 11 月 · 臺南市 {len(results)} 區</div>
    <h1>腳踏車掃街，<em>覆蓋率僅 {overall_pct:.1f}%</em></h1>
    <p class="hero-sub">陳亭妃宣稱以腳踏車走訪臺南 {len(results)} 個行政區。我們將 GPS 軌跡與臺南市門牌道路網比對，計算實際覆蓋率。結果顯示：{total_road:,.1f} 公里的道路，花了 {hours_int} 小時 {minutes_rem} 分鐘只走過 {total_covered:,.0f} 公里。</p>
  </div>
  <div class="numbers">
    <div class="num-card">
      <div class="num-label">道路總長</div>
      <div class="num-value">{total_road:,.1f}<span class="unit">km</span></div>
      <div class="num-note">{len(results)} 區門牌路網</div>
    </div>
    <div class="num-card">
      <div class="num-label">已覆蓋</div>
      <div class="num-value">{total_covered:,.1f}<span class="unit">km</span></div>
      <div class="num-note">2026-11 軌跡</div>
    </div>
    <div class="num-card">
      <div class="num-label">整體覆蓋率</div>
      <div class="num-value critical">{overall_pct:.1f}<span class="unit">%</span></div>
    </div>
    <div class="num-card">
      <div class="num-label">零覆蓋行政區</div>
      <div class="num-value critical">{zero_count}<span class="unit">/ {len(results)}</span></div>
      <div class="num-note">超過半數完全未到</div>
    </div>
    <div class="num-card">
      <div class="num-label">實際騎乘時間</div>
      <div class="num-value">{hours_int}<span class="unit">時</span> {minutes_rem}<span class="unit">分</span></div>
      <div class="num-note">排除停留間隔 &gt;10 分鐘</div>
    </div>
  </div>
  <div class="context">
    臺南是全台面積最大的直轄市，光是計算門牌對應的路線就超過一萬公里。<strong>陳亭妃花了 {hours_int} 小時 {minutes_rem} 分鐘、騎了約 {total_covered:,.0f} 公里，整體覆蓋率僅 {overall_pct:.1f}%</strong>，其中 {zero_count} 個行政區完全沒有軌跡紀錄。與其進行這樣低效且無感的掃街行程，不如站上電視辯論台，好好向所有臺南市民說清楚，要如何處理臺南眼前的各種爭議。
  </div>
  <div class="section-head">
    <h2>各行政區覆蓋率</h2>
    <p>依覆蓋率排序 · 水平軸為該區道路被 GPS 軌跡覆蓋的比例</p>
  </div>
  <div class="chart-container" id="barChart"></div>
  <div class="section-head">
    <h2>完整數據</h2>
    <p>{len(results)} 個行政區的道路長度與覆蓋詳情</p>
  </div>
  <div class="toggle-wrap">
    <button class="toggle-btn" id="sortToggle">依覆蓋率排序</button>
  </div>
  <div style="overflow-x:auto">
    <table class="detail-table" id="detailTable">
      <thead>
        <tr><th>行政區</th><th class="r">道路 (km)</th><th class="r">覆蓋 (km)</th><th class="r">覆蓋率</th><th></th></tr>
      </thead>
      <tbody id="tableBody"></tbody>
      <tfoot id="tableFoot"></tfoot>
    </table>
  </div>
  <div class="footer">
    <p>資料來源：臺南市門牌道路圖資（行政區路網長度）、GPS 巡查軌跡（2026 年 11 月）</p>
    <p>方法：以各行政區道路 LineString 建立凸包，將 GPS 軌跡以 15m 緩衝區與道路網交集，計算實際覆蓋長度。</p>
    <p>報告產生時間：{generated_at}</p>
  </div>
</div>
<script>
var DATA = {data_js};
var maxPct = Math.max.apply(null, DATA.map(function(d){{ return d.pct; }}));
var chartScale = Math.max(maxPct * 1.15, 10);
function renderBarChart() {{
  var sorted = DATA.slice().sort(function(a, b){{ return b.pct - a.pct; }});
  var el = document.getElementById('barChart');
  el.innerHTML = '';
  sorted.forEach(function(d) {{
    var row = document.createElement('div');
    row.className = 'bar-row';
    var w = (d.pct / chartScale * 100).toFixed(1);
    row.innerHTML =
      '<div class="bar-name">' + d.name + '</div>' +
      '<div class="bar-track"><div class="bar-fill' + (d.pct === 0 ? ' zero' : '') + '" style="width:' + w + '%"></div></div>' +
      '<div class="bar-pct' + (d.pct === 0 ? ' zero' : '') + '">' + d.pct.toFixed(1) + '%</div>' +
      '<div class="tooltip">' + d.name + '：道路 ' + d.road.toFixed(1) + ' km，覆蓋 ' + d.covered.toFixed(2) + ' km（' + d.pct.toFixed(1) + '%）</div>';
    el.appendChild(row);
  }});
}}
var tableSortByPct = false;
function renderTable() {{
  var list = tableSortByPct ? DATA.slice().sort(function(a, b){{ return b.pct - a.pct; }}) : DATA.slice();
  var tbody = document.getElementById('tableBody');
  tbody.innerHTML = '';
  var totalRoad = 0, totalCovered = 0;
  list.forEach(function(d) {{
    totalRoad += d.road;
    totalCovered += d.covered;
    var z = d.pct === 0;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + d.name + '</td>' +
      '<td class="r">' + d.road.toFixed(1) + '</td>' +
      '<td class="r' + (z ? ' zero' : '') + '">' + d.covered.toFixed(2) + '</td>' +
      '<td class="r' + (z ? ' zero' : '') + '">' + d.pct.toFixed(1) + '%</td>' +
      '<td><span class="mini-bar"><span class="mini-fill" style="width:' + (d.pct / chartScale * 100).toFixed(1) + '%"></span></span></td>';
    tbody.appendChild(tr);
  }});
  var overallPct = (totalCovered / totalRoad * 100);
  document.getElementById('tableFoot').innerHTML =
    '<tr><td>合計</td><td class="r">' + totalRoad.toFixed(1) + '</td><td class="r">' + totalCovered.toFixed(2) + '</td><td class="r">' + overallPct.toFixed(1) + '%</td><td></td></tr>';
}}
document.getElementById('sortToggle').addEventListener('click', function() {{
  tableSortByPct = !tableSortByPct;
  this.textContent = tableSortByPct ? '依行政區排序' : '依覆蓋率排序';
  renderTable();
}});
renderBarChart();
renderTable();
</script>
</body>
</html>"""
    return html


def main():
    district_names = load_district_names(DATA_JS)

    print("Loading GPS paths from docs/2026-11*...")
    gps_paths, timestamps = load_gps_paths(PATHS_GLOB)
    active_hours, session_count = calculate_active_hours(timestamps)
    print(f"  Loaded {len(gps_paths)} GPS traces")
    print(f"  Active time: {active_hours:.1f} hours across {session_count} sessions\n")

    district_files = sorted(glob.glob(os.path.join(TAINAN_DIR, "*.geojson")))
    print(f"Processing {len(district_files)} districts...\n")

    results = []
    for geojson_path in district_files:
        polygon, total_km, code, road_lines = load_district(geojson_path)
        cn_name = district_names.get(code, code)
        total_road_m, covered_m = calculate_coverage(polygon, road_lines, gps_paths)

        coverage_pct = (covered_m / total_road_m * 100) if total_road_m > 0 else 0
        results.append({
            "district": code,
            "name": cn_name,
            "total_road_km": total_km,
            "measured_road_m": total_road_m,
            "covered_m": covered_m,
            "coverage_pct": coverage_pct,
        })

        print(f"  {cn_name}({code}): road={total_km:.1f}km, "
              f"covered={covered_m/1000:.2f}km, "
              f"coverage={coverage_pct:.1f}%")

    generated_at = datetime.now().strftime("%Y-%m-%d %H:%M")
    html = generate_html(results, len(gps_paths), generated_at,
                         active_hours, session_count)

    os.makedirs(os.path.dirname(OUTPUT_HTML), exist_ok=True)
    with open(OUTPUT_HTML, "w", encoding="utf-8") as f:
        f.write(html)
    print(f"\nReport saved to {OUTPUT_HTML}")


if __name__ == "__main__":
    main()
