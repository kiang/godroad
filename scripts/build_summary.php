<?php

/**
 * Build summary JSON data from daily GPS JSON records
 * Usage:
 *   php scripts/build_summary.php [YYYYMMDD]  - Build specific date
 *   php scripts/build_summary.php --all       - Build all dates
 *   php scripts/build_summary.php --index     - Only rebuild index
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

    /**
     * Build all available dates
     */
    public function buildAll(): void
    {
        $dates = $this->getAvailableDates();
        foreach ($dates as $date) {
            $this->build($date);
        }
        $this->buildIndex();
    }

    /**
     * Build index file only
     */
    public function buildIndex(): void
    {
        $dates = $this->getAvailableDates();
        $index = [];

        foreach ($dates as $date) {
            $dateFormatted = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            $dataFile = $this->docsDir . '/data/' . $dateFormatted . '.json';

            if (file_exists($dataFile)) {
                $data = json_decode(file_get_contents($dataFile), true);
                $index[] = [
                    'date' => $dateFormatted,
                    'projectName' => $data['projectName'] ?? '',
                    'totalDistance' => $data['totalDistance'] ?? 0,
                    'recordCount' => count($data['timeline'] ?? []),
                ];
            }
        }

        // Sort by date descending
        usort($index, fn($a, $b) => strcmp($b['date'], $a['date']));

        $outputDir = $this->docsDir . '/data';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        file_put_contents(
            $outputDir . '/index.json',
            json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        echo "Generated index with " . count($index) . " dates\n";
    }

    /**
     * Get list of available dates (YYYYMMDD format)
     */
    private function getAvailableDates(): array
    {
        $dates = [];
        $dirs = glob($this->docsDir . '/20*-*-*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $datePart = basename($dir);
            // Convert YYYY-MM-DD to YYYYMMDD
            $date = str_replace('-', '', $datePart);
            if (preg_match('/^\d{8}$/', $date)) {
                $dates[] = $date;
            }
        }

        sort($dates);
        return $dates;
    }

    public function build(string $date): void
    {
        $dateFormatted = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        $sourceDir = $this->docsDir . '/' . $dateFormatted;

        if (!is_dir($sourceDir)) {
            echo "Skipping {$dateFormatted}: Directory not found\n";
            return;
        }

        $records = $this->loadRecords($sourceDir);

        if (empty($records)) {
            echo "Skipping {$dateFormatted}: No records found\n";
            return;
        }

        // Filter out discontinuous points (large time gaps indicate init/stale data)
        $records = $this->filterContinuousRecords($records);

        if (empty($records)) {
            echo "Skipping {$dateFormatted}: No continuous records found\n";
            return;
        }

        // Merge consecutive points with minimal movement
        $records = $this->mergeStationaryPoints($records);

        $summaryData = $this->generateSummaryData($date, $dateFormatted, $records);

        $outputDir = $this->docsDir . '/data';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFile = $outputDir . '/' . $dateFormatted . '.json';
        file_put_contents(
            $outputFile,
            json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

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

            $currRecord = $records[$i];

            // Compare to the LAST MERGED point, not the previous original record
            $lastMerged = $merged[count($merged) - 1];
            $prevPoint = $lastMerged['data']['GPS'][0] ?? null;
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

    private function generateSummaryData(string $date, string $dateFormatted, array $records): array
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
                                'pointIndex' => count($allPoints),  // Index in allPoints array
                                'time' => $record['time'],
                                'lat' => $firstPoint['lat'],
                                'lng' => $firstPoint['lng'],
                                'addr' => $record['data']['addr'] ?? '',
                                'cumulativeDistance' => $totalDistance,
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

        // Calculate segments between start, rest stops, and end
        $segments = [];
        $startTime = $timeline[0]['time'] ?? '';
        $endTime = $timeline[count($timeline) - 1]['time'] ?? '';
        $startAddr = $timeline[0]['addr'] ?? '';
        $endAddr = $timeline[count($timeline) - 1]['addr'] ?? '';
        $totalPoints = count($allPoints);

        $prevDistance = 0;
        $prevName = '起點';
        $prevTime = $startTime;
        $prevAddr = $startAddr;
        $prevPointIndex = 0;

        foreach ($restStops as $i => $stop) {
            $segmentDistance = ($stop['cumulativeDistance'] - $prevDistance) / 1000;
            $segments[] = [
                'from' => $prevName,
                'fromTime' => $prevTime,
                'fromAddr' => $prevAddr,
                'fromPointIndex' => $prevPointIndex,
                'to' => '休息 ' . ($i + 1),
                'toTime' => $stop['time'],
                'toAddr' => $stop['addr'],
                'toPointIndex' => $stop['pointIndex'],
                'distance' => round($segmentDistance, 2),
            ];
            $prevDistance = $stop['cumulativeDistance'];
            $prevName = '休息 ' . ($i + 1);
            $prevTime = $stop['time'];
            $prevAddr = $stop['addr'];
            $prevPointIndex = $stop['pointIndex'];
        }

        // Add final segment to end
        $finalSegmentDistance = ($totalDistance - $prevDistance) / 1000;
        $segments[] = [
            'from' => $prevName,
            'fromTime' => $prevTime,
            'fromAddr' => $prevAddr,
            'fromPointIndex' => $prevPointIndex,
            'to' => '終點',
            'toTime' => $endTime,
            'toAddr' => $endAddr,
            'toPointIndex' => $totalPoints - 1,
            'distance' => round($finalSegmentDistance, 2),
        ];

        return [
            'date' => $dateFormatted,
            'projectName' => $projectName,
            'dotName' => $dotName,
            'totalDistance' => round($totalDistance),
            'totalDistanceKm' => round($totalDistance / 1000, 2),
            'restCount' => count($restStops),
            'anomalyCount' => count($speedAnomalies),
            'center' => [
                'lat' => $centerLat,
                'lng' => $centerLng,
            ],
            'points' => $allPoints,
            'timeline' => $timeline,
            'restStops' => $restStops,
            'speedAnomalies' => $speedAnomalies,
            'segments' => $segments,
        ];
    }
}

// Main execution
$builder = new SummaryBuilder();

if (isset($argv[1])) {
    if ($argv[1] === '--all') {
        $builder->buildAll();
    } elseif ($argv[1] === '--index') {
        $builder->buildIndex();
    } else {
        $builder->build($argv[1]);
        $builder->buildIndex();
    }
} else {
    $builder->build(date('Ymd'));
    $builder->buildIndex();
}
