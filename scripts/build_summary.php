<?php

/**
 * Build summary HTML page from daily GPS JSON records
 * Usage: php scripts/build_summary.php [YYYYMMDD]
 */

class SummaryBuilder
{
    private string $docsDir;

    // Speed thresholds (km/h)
    private const MAX_CYCLING_SPEED = 50;  // Above this is likely not cycling
    private const MIN_MOVING_SPEED = 2;    // Below this is likely resting

    // Distance threshold (meters)
    private const REST_DISTANCE_THRESHOLD = 50;

    // Time gap threshold (seconds) - points with larger gaps are considered discontinuous
    private const MAX_TIME_GAP = 300;  // 5 minutes

    // Minimum movement threshold (meters) - consecutive points below this are merged
    private const MIN_MOVEMENT_THRESHOLD = 10;

    public function __construct()
    {
        $this->docsDir = dirname(__DIR__) . '/docs';
    }

    public function build(string $date): void
    {
        $dateFormatted = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        $sourceDir = $this->docsDir . '/' . $dateFormatted;

        if (!is_dir($sourceDir)) {
            echo "Error: Directory not found: {$sourceDir}\n";
            return;
        }

        $records = $this->loadRecords($sourceDir);

        if (empty($records)) {
            echo "Error: No records found in {$sourceDir}\n";
            return;
        }

        // Filter out discontinuous points (large time gaps indicate init/stale data)
        $records = $this->filterContinuousRecords($records);

        if (empty($records)) {
            echo "Error: No continuous records found in {$sourceDir}\n";
            return;
        }

        // Merge consecutive points with minimal movement
        $records = $this->mergeStationaryPoints($records);

        $html = $this->generateHtml($date, $dateFormatted, $records);

        $outputDir = $this->docsDir . '/page';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFile = $outputDir . '/' . $date . '.html';
        file_put_contents($outputFile, $html);

        echo "Generated: {$outputFile}\n";
        echo "Total records: " . count($records) . "\n";
    }

    private function loadRecords(string $dir): array
    {
        $files = glob($dir . '/*.json');
        sort($files);

        $records = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data && isset($data[0])) {
                $time = basename($file, '.json');
                $records[] = [
                    'time' => substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2),
                    'timestamp' => $time,
                    'data' => $data[0],
                ];
            }
        }

        return $records;
    }

    /**
     * Filter records to only include continuous segments
     * Points with large time gaps before them are considered discontinuous
     */
    private function filterContinuousRecords(array $records): array
    {
        if (count($records) < 2) {
            return $records;
        }

        // First pass: mark each record with gap info
        $segments = [];
        $currentSegment = [];

        for ($i = 0; $i < count($records); $i++) {
            if ($i === 0) {
                $currentSegment[] = $records[$i];
                continue;
            }

            $timeDiff = $this->calculateTimeDiff(
                $records[$i - 1]['timestamp'],
                $records[$i]['timestamp']
            );

            if ($timeDiff > self::MAX_TIME_GAP) {
                // Gap detected - save current segment and start new one
                if (count($currentSegment) > 1) {
                    $segments[] = $currentSegment;
                }
                $currentSegment = [$records[$i]];
            } else {
                $currentSegment[] = $records[$i];
            }
        }

        // Don't forget the last segment
        if (count($currentSegment) > 1) {
            $segments[] = $currentSegment;
        }

        // Find the longest continuous segment (likely the actual ride)
        if (empty($segments)) {
            return [];
        }

        $longestSegment = [];
        foreach ($segments as $segment) {
            if (count($segment) > count($longestSegment)) {
                $longestSegment = $segment;
            }
        }

        return $longestSegment;
    }

    /**
     * Merge consecutive points with minimal movement into single entries
     * Keeps the first point of each stationary period with duration info
     */
    private function mergeStationaryPoints(array $records): array
    {
        if (count($records) < 2) {
            return $records;
        }

        $merged = [];
        $stationaryStart = null;
        $stationaryCount = 0;

        for ($i = 0; $i < count($records); $i++) {
            if ($i === 0) {
                $merged[] = $records[$i];
                continue;
            }

            $prevRecord = $records[$i - 1];
            $currRecord = $records[$i];

            $prevPoint = $prevRecord['data']['GPS'][0] ?? null;
            $currPoint = $currRecord['data']['GPS'][0] ?? null;

            if (!$prevPoint || !$currPoint) {
                $merged[] = $currRecord;
                continue;
            }

            $distance = $this->calculateDistance(
                $prevPoint['lat'], $prevPoint['lng'],
                $currPoint['lat'], $currPoint['lng']
            );

            if ($distance < self::MIN_MOVEMENT_THRESHOLD) {
                // Minimal movement - skip this point but track duration
                if ($stationaryStart === null) {
                    $stationaryStart = count($merged) - 1;
                }
                $stationaryCount++;

                // Update the stationary start record with end time info
                if ($stationaryStart >= 0 && isset($merged[$stationaryStart])) {
                    $merged[$stationaryStart]['stationaryUntil'] = $currRecord['time'];
                    $merged[$stationaryStart]['stationaryDuration'] = $stationaryCount;
                }
            } else {
                // Significant movement - add this point
                $stationaryStart = null;
                $stationaryCount = 0;
                $merged[] = $currRecord;
            }
        }

        return $merged;
    }

    /**
     * Calculate distance between two points using Haversine formula
     * @return float Distance in meters
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Calculate time difference in seconds between two timestamps (HHMMSS format)
     */
    private function calculateTimeDiff(string $time1, string $time2): int
    {
        $h1 = (int)substr($time1, 0, 2);
        $m1 = (int)substr($time1, 2, 2);
        $s1 = (int)substr($time1, 4, 2);

        $h2 = (int)substr($time2, 0, 2);
        $m2 = (int)substr($time2, 2, 2);
        $s2 = (int)substr($time2, 4, 2);

        return ($h2 * 3600 + $m2 * 60 + $s2) - ($h1 * 3600 + $m1 * 60 + $s1);
    }

    private function generateHtml(string $date, string $dateFormatted, array $records): string
    {
        $projectName = $records[0]['data']['pjName'] ?? 'GPS Tracking';
        $dotName = $records[0]['data']['dot_name'] ?? '';

        // Extract all GPS points and analyze movement
        $allPoints = [];
        $timeline = [];
        $totalDistance = 0;
        $restStops = [];
        $speedAnomalies = [];

        $prevPoint = null;
        $prevTimestamp = null;
        $currentRestStart = null;
        $isResting = false;

        foreach ($records as $index => $record) {
            $gpsData = $record['data']['GPS'] ?? [];
            $firstPoint = $gpsData[0] ?? null;

            if ($firstPoint) {
                $distance = 0;
                $speed = 0;
                $timeDiff = 0;
                $status = 'normal';

                if ($prevPoint !== null && $prevTimestamp !== null) {
                    $distance = $this->calculateDistance(
                        $prevPoint['lat'], $prevPoint['lng'],
                        $firstPoint['lat'], $firstPoint['lng']
                    );
                    $timeDiff = $this->calculateTimeDiff($prevTimestamp, $record['timestamp']);

                    if ($timeDiff > 0) {
                        $speed = ($distance / $timeDiff) * 3.6; // Convert m/s to km/h
                    }

                    $totalDistance += $distance;

                    // Check for anomalies
                    if ($speed > self::MAX_CYCLING_SPEED) {
                        $status = 'speed_anomaly';
                        $speedAnomalies[] = [
                            'index' => $index,
                            'time' => $record['time'],
                            'speed' => round($speed, 1),
                            'lat' => $firstPoint['lat'],
                            'lng' => $firstPoint['lng'],
                        ];
                        // End any current rest period
                        if ($isResting && $currentRestStart !== null) {
                            $isResting = false;
                            $currentRestStart = null;
                        }
                    } elseif ($distance < self::REST_DISTANCE_THRESHOLD) {
                        $status = 'rest';
                        // Start new rest period or continue existing one
                        if (!$isResting) {
                            $isResting = true;
                            $currentRestStart = [
                                'index' => $index,
                                'time' => $record['time'],
                                'lat' => $firstPoint['lat'],
                                'lng' => $firstPoint['lng'],
                                'addr' => $record['data']['addr'] ?? '',
                            ];
                            $restStops[] = $currentRestStart;
                        }
                    } else {
                        // Moving - end any current rest period
                        if ($isResting) {
                            $isResting = false;
                            $currentRestStart = null;
                        }
                    }
                }

                $allPoints[] = $firstPoint;
                $timelineEntry = [
                    'time' => $record['time'],
                    'lat' => $firstPoint['lat'],
                    'lng' => $firstPoint['lng'],
                    'addr' => $record['data']['addr'] ?? '',
                    'power' => $record['data']['dot_Power'] ?? '',
                    'distance' => round($distance),
                    'speed' => round($speed, 1),
                    'status' => $status,
                    'stationaryUntil' => $record['stationaryUntil'] ?? '',
                    'stationaryDuration' => $record['stationaryDuration'] ?? 0,
                ];
                $timeline[] = $timelineEntry;

                $prevPoint = $firstPoint;
                $prevTimestamp = $record['timestamp'];
            }
        }

        // Calculate map center
        $centerLat = array_sum(array_column($allPoints, 'lat')) / count($allPoints);
        $centerLng = array_sum(array_column($allPoints, 'lng')) / count($allPoints);

        $pointsJson = json_encode($allPoints);
        $timelineJson = json_encode($timeline, JSON_UNESCAPED_UNICODE);
        $restStopsJson = json_encode($restStops, JSON_UNESCAPED_UNICODE);
        $speedAnomaliesJson = json_encode($speedAnomalies, JSON_UNESCAPED_UNICODE);
        $totalDistanceKm = round($totalDistance / 1000, 2);
        $restCount = count($restStops);
        $anomalyCount = count($speedAnomalies);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$projectName} - {$dateFormatted}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .header {
            background: #2c3e50;
            color: white;
            padding: 15px 20px;
        }
        .header h1 { font-size: 1.2em; margin-bottom: 5px; }
        .header .meta { font-size: 0.9em; opacity: 0.8; }
        #map { height: 55vh; width: 100%; }
        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            padding: 10px 20px;
            background: #ecf0f1;
            font-size: 0.85em;
        }
        .stat-item span { font-weight: bold; color: #2c3e50; }
        .stat-item.warning span { color: #e74c3c; }
        .stat-item.rest span { color: #f39c12; }
        .legend {
            display: flex;
            gap: 15px;
            padding: 8px 20px;
            background: #fff;
            border-bottom: 1px solid #ddd;
            font-size: 0.8em;
        }
        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        .legend-dot.normal { background: #3498db; }
        .legend-dot.rest { background: #f39c12; }
        .legend-dot.anomaly { background: #e74c3c; }
        .timeline {
            max-height: 30vh;
            overflow-y: auto;
            padding: 10px;
            background: #f5f5f5;
        }
        .timeline-item {
            display: flex;
            padding: 8px 10px;
            border-bottom: 1px solid #ddd;
            cursor: pointer;
            transition: background 0.2s;
            align-items: center;
        }
        .timeline-item:hover { background: #e0e0e0; }
        .timeline-item.active { background: #d4edda; }
        .timeline-item.rest { background: #fef9e7; border-left: 3px solid #f39c12; }
        .timeline-item.speed_anomaly { background: #fdedec; border-left: 3px solid #e74c3c; }
        .timeline-time {
            font-weight: bold;
            min-width: 70px;
            color: #2c3e50;
            font-size: 0.85em;
        }
        .timeline-addr {
            flex: 1;
            color: #555;
            font-size: 0.9em;
        }
        .timeline-speed {
            width: 70px;
            text-align: right;
            color: #888;
            font-size: 0.85em;
        }
        .timeline-speed.high { color: #e74c3c; font-weight: bold; }
        .timeline-distance {
            width: 60px;
            text-align: right;
            color: #888;
            font-size: 0.85em;
        }
        .timeline-status {
            width: 20px;
            text-align: center;
        }
        .custom-marker {
            background: transparent !important;
            border: none !important;
        }
        .playback-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px 20px;
            background: #34495e;
            color: white;
            font-size: 0.9em;
        }
        .play-btn, .stop-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: bold;
        }
        .play-btn {
            background: #27ae60;
            color: white;
        }
        .play-btn:hover { background: #2ecc71; }
        .play-btn.playing {
            background: #f39c12;
        }
        .play-btn.playing:hover { background: #e67e22; }
        .stop-btn {
            background: #e74c3c;
            color: white;
        }
        .stop-btn:hover { background: #c0392b; }
        .playback-controls select {
            padding: 5px 10px;
            border-radius: 4px;
            border: none;
        }
        .playback-controls label {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .progress-text {
            margin-left: auto;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{$projectName}</h1>
        <div class="meta">{$dotName} | {$dateFormatted}</div>
    </div>
    <div class="stats">
        <div class="stat-item">紀錄數：<span id="totalRecords">0</span></div>
        <div class="stat-item">總距離：<span>{$totalDistanceKm} 公里</span></div>
        <div class="stat-item">起始時間：<span id="firstTime">-</span></div>
        <div class="stat-item">結束時間：<span id="lastTime">-</span></div>
        <div class="stat-item rest">休息次數：<span>{$restCount}</span></div>
        <div class="stat-item warning">速度異常：<span>{$anomalyCount}</span></div>
    </div>
    <div class="legend">
        <div class="legend-item"><div class="legend-dot normal"></div> 正常</div>
        <div class="legend-item"><div class="legend-dot rest"></div> 休息</div>
        <div class="legend-item"><div class="legend-dot anomaly"></div> 速度異常 (&gt;50 km/h)</div>
    </div>
    <div class="playback-controls">
        <button id="playBtn" class="play-btn">▶ 播放</button>
        <button id="stopBtn" class="stop-btn">⏹ 停止</button>
        <label>速度：<select id="playSpeed">
            <option value="1000">1x</option>
            <option value="500" selected>2x</option>
            <option value="250">4x</option>
            <option value="100">10x</option>
        </select></label>
        <span class="progress-text">進度：<span id="currentIndex">0</span> / <span id="totalPoints">0</span></span>
    </div>
    <div id="map"></div>
    <div class="timeline" id="timeline"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const points = {$pointsJson};
        const timeline = {$timelineJson};
        const restStops = {$restStopsJson};
        const speedAnomalies = {$speedAnomaliesJson};

        // Initialize map
        const map = L.map('map').setView([{$centerLat}, {$centerLng}], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Draw route polyline
        const routeCoords = points.map(p => [p.lat, p.lng]);
        const polyline = L.polyline(routeCoords, { color: '#3498db', weight: 5, opacity: 0.8 }).addTo(map);
        map.fitBounds(polyline.getBounds(), { padding: [20, 20] });

        // Add markers for start and end
        if (points.length > 0) {
            L.marker([points[0].lat, points[0].lng], {
                icon: L.divIcon({
                    className: 'custom-marker',
                    html: '<div style="background:#27ae60;color:white;padding:6px 12px;border-radius:4px;font-size:14px;font-weight:bold;white-space:nowrap;box-shadow:0 2px 5px rgba(0,0,0,0.3);">起點</div>',
                    iconSize: null,
                    iconAnchor: [25, 20]
                })
            }).addTo(map);

            L.marker([points[points.length - 1].lat, points[points.length - 1].lng], {
                icon: L.divIcon({
                    className: 'custom-marker',
                    html: '<div style="background:#9b59b6;color:white;padding:6px 12px;border-radius:4px;font-size:14px;font-weight:bold;white-space:nowrap;box-shadow:0 2px 5px rgba(0,0,0,0.3);">終點</div>',
                    iconSize: null,
                    iconAnchor: [25, 20]
                })
            }).addTo(map);
        }

        // Add rest stop markers
        restStops.forEach(stop => {
            L.circleMarker([stop.lat, stop.lng], {
                radius: 8, fillColor: '#f39c12', color: '#fff', weight: 2, fillOpacity: 0.9
            }).addTo(map).bindPopup('<b>休息站</b><br>' + stop.time + '<br>' + stop.addr);
        });

        // Add speed anomaly markers
        speedAnomalies.forEach(anomaly => {
            L.circleMarker([anomaly.lat, anomaly.lng], {
                radius: 8, fillColor: '#e74c3c', color: '#fff', weight: 2, fillOpacity: 0.9
            }).addTo(map).bindPopup('<b>速度異常</b><br>' + anomaly.time + '<br>' + anomaly.speed + ' km/h');
        });

        // Current position marker
        let currentMarker = null;

        // Render timeline
        const timelineEl = document.getElementById('timeline');
        timeline.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'timeline-item ' + item.status;

            let statusIcon = '';
            if (item.status === 'rest') statusIcon = '<span style="color:#f39c12">&#9679;</span>';
            else if (item.status === 'speed_anomaly') statusIcon = '<span style="color:#e74c3c">&#9679;</span>';

            const speedClass = item.speed > 50 ? 'high' : '';

            // Show time range if stationary
            let timeDisplay = item.time;
            if (item.stationaryUntil) {
                timeDisplay = item.time + ' - ' + item.stationaryUntil;
            }

            div.innerHTML = '<span class="timeline-status">' + statusIcon + '</span>' +
                '<span class="timeline-time">' + timeDisplay + '</span>' +
                '<span class="timeline-addr">' + item.addr + '</span>' +
                '<span class="timeline-distance">' + item.distance + ' 公尺</span>' +
                '<span class="timeline-speed ' + speedClass + '">' + item.speed + ' km/h</span>';

            div.addEventListener('click', () => {
                document.querySelectorAll('.timeline-item').forEach(el => el.classList.remove('active'));
                div.classList.add('active');

                if (currentMarker) map.removeLayer(currentMarker);
                const markerColor = item.status === 'rest' ? '#f39c12' : (item.status === 'speed_anomaly' ? '#e74c3c' : '#3498db');
                currentMarker = L.circleMarker([item.lat, item.lng], {
                    radius: 10, fillColor: markerColor, color: '#fff', weight: 2, fillOpacity: 0.9
                }).addTo(map).bindPopup('<b>' + item.time + '</b><br>' + item.addr + '<br>速度：' + item.speed + ' km/h').openPopup();
                map.setView([item.lat, item.lng], 16);
            });
            timelineEl.appendChild(div);
        });

        // Update stats
        document.getElementById('totalRecords').textContent = timeline.length;
        document.getElementById('totalPoints').textContent = timeline.length;
        if (timeline.length > 0) {
            document.getElementById('firstTime').textContent = timeline[0].time;
            document.getElementById('lastTime').textContent = timeline[timeline.length - 1].time;
        }

        // Autoplay functionality
        let playInterval = null;
        let currentPlayIndex = 0;
        let isPlaying = false;
        const playBtn = document.getElementById('playBtn');
        const stopBtn = document.getElementById('stopBtn');
        const playSpeed = document.getElementById('playSpeed');
        const currentIndexEl = document.getElementById('currentIndex');
        const timelineItems = document.querySelectorAll('.timeline-item');

        function showPoint(index) {
            if (index < 0 || index >= timeline.length) return;

            const item = timeline[index];
            currentPlayIndex = index;
            currentIndexEl.textContent = index + 1;

            // Update timeline highlight
            timelineItems.forEach(el => el.classList.remove('active'));
            timelineItems[index].classList.add('active');
            timelineItems[index].scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Update map marker
            if (currentMarker) map.removeLayer(currentMarker);
            const markerColor = item.status === 'rest' ? '#f39c12' : (item.status === 'speed_anomaly' ? '#e74c3c' : '#3498db');
            currentMarker = L.circleMarker([item.lat, item.lng], {
                radius: 12, fillColor: markerColor, color: '#fff', weight: 3, fillOpacity: 0.9
            }).addTo(map).bindPopup('<b>' + item.time + '</b><br>' + item.addr + '<br>速度：' + item.speed + ' km/h');

            map.setView([item.lat, item.lng], 15);
        }

        function startPlayback() {
            if (isPlaying) {
                // Pause
                clearInterval(playInterval);
                isPlaying = false;
                playBtn.textContent = '▶ 播放';
                playBtn.classList.remove('playing');
            } else {
                // Play
                isPlaying = true;
                playBtn.textContent = '⏸ 暫停';
                playBtn.classList.add('playing');

                playInterval = setInterval(() => {
                    if (currentPlayIndex >= timeline.length - 1) {
                        stopPlayback();
                        return;
                    }
                    showPoint(currentPlayIndex + 1);
                }, parseInt(playSpeed.value));
            }
        }

        function stopPlayback() {
            clearInterval(playInterval);
            isPlaying = false;
            currentPlayIndex = 0;
            currentIndexEl.textContent = 0;
            playBtn.textContent = '▶ 播放';
            playBtn.classList.remove('playing');
            if (currentMarker) map.removeLayer(currentMarker);
            timelineItems.forEach(el => el.classList.remove('active'));
            map.fitBounds(polyline.getBounds(), { padding: [20, 20] });
        }

        playBtn.addEventListener('click', startPlayback);
        stopBtn.addEventListener('click', stopPlayback);

        playSpeed.addEventListener('change', () => {
            if (isPlaying) {
                clearInterval(playInterval);
                playInterval = setInterval(() => {
                    if (currentPlayIndex >= timeline.length - 1) {
                        stopPlayback();
                        return;
                    }
                    showPoint(currentPlayIndex + 1);
                }, parseInt(playSpeed.value));
            }
        });
    </script>
</body>
</html>
HTML;
    }
}

// Main execution
$date = $argv[1] ?? date('Ymd');
$builder = new SummaryBuilder();
$builder->build($date);
