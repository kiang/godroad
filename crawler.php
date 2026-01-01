<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GpsDataCrawler
{
    private Client $client;
    private string $baseDir;
    private string $endpoint = 'https://gps.godroad.tw/Sever';

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => true,
        ]);
        $this->baseDir = __DIR__ . '/docs';
    }

    public function crawl(string $saId): void
    {
        try {
            $data = $this->fetchData($saId);

            if ($data === null) {
                $this->log("Failed to fetch data for SA_ID: {$saId}");
                return;
            }

            $dateDir = $this->baseDir . '/' . date('Y-m-d');
            $this->ensureDirectory($dateDir);

            if (!$this->hasContentChanged($dateDir, $data)) {
                $this->log("No changes detected for SA_ID: {$saId}, skipping save.");
                return;
            }

            $filename = $dateDir . '/' . date('His') . '.json';
            $this->saveData($filename, $data);
            $this->log("Saved new data to: {$filename}");

        } catch (GuzzleException $e) {
            $this->log("HTTP Error: " . $e->getMessage());
        } catch (\Exception $e) {
            $this->log("Error: " . $e->getMessage());
        }
    }

    private function fetchData(string $saId): ?array
    {
        $response = $this->client->get($this->endpoint, [
            'query' => ['SA_ID' => $saId],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON decode error: " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function hasContentChanged(string $dateDir, array $newData): bool
    {
        $latestFile = $this->getLatestFile($dateDir);

        if ($latestFile === null) {
            return true;
        }

        $existingContent = file_get_contents($latestFile);
        $existingData = json_decode($existingContent, true);

        if ($existingData === null) {
            return true;
        }

        // Compare JSON content (normalized)
        $existingHash = md5(json_encode($existingData, JSON_UNESCAPED_UNICODE));
        $newHash = md5(json_encode($newData, JSON_UNESCAPED_UNICODE));

        return $existingHash !== $newHash;
    }

    private function getLatestFile(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.json');

        if (empty($files)) {
            return null;
        }

        // Sort by filename (time-based) descending
        rsort($files);

        return $files[0];
    }

    private function saveData(string $filename, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filename, $json);
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}" . PHP_EOL;
    }
}

// Main execution
$crawler = new GpsDataCrawler();
$crawler->crawl('E260102');
