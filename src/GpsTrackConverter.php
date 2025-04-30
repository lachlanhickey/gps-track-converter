<?php

namespace App;

use SimpleXMLElement;
use Exception;
use ZipArchive;
use Ramsey\Uuid\Uuid;

class GpsTrackConverter
{
    /**
     * Convert GPS file to LineString
     *
     * @param string $filePath Path to the file
     * @param string $fileType File type (gpx, kmz, kml)
     * @return array LineString data with distance information
     */
    public function convert($filePath, $fileType = null)
    {
        if (!$fileType) {
            $fileType = $this->detectFileType($filePath);
        }

        $coordinates = $this->extractCoordinates($filePath, $fileType);
        
        if (empty($coordinates)) {
            throw new Exception('No coordinates found in the file');
        }

        $lineString = $this->coordinatesToLineString($coordinates);
        $densifiedLineString = $this->densifyLineString($lineString, 100); // 100 meters
        
        // Calculate the distances from start
        $distancesFromStart = $this->calculateDistanceFromStart($densifiedLineString);
        
        // Create the formatted array of objects
        $formattedLineString = [];
        foreach ($densifiedLineString as $index => $point) {
            // Create a standard class object for each point
            $pointObject = new \stdClass();
            $pointObject->lat = $point['lat'];
            $pointObject->lon = $point['lon'];
            $pointObject->elevation = $point['ele'];
            $pointObject->distance_from_start = $distancesFromStart[$index];
            
            $formattedLineString[] = $pointObject;
        }
        
        // Create a result object
        $result = new \stdClass();
        $result->points = $formattedLineString;
        $result->totalDistance = $this->calculateTotalDistance($densifiedLineString);
        $result->originalPointCount = count($lineString);
        $result->densifiedPointCount = count($densifiedLineString);
        
        return $result;
    }

    /**
     * Detect file type based on file extension
     *
     * @param string $filePath
     * @return string
     */
    private function detectFileType($filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($extension === 'gpx') {
            return 'gpx';
        } elseif ($extension === 'kmz') {
            return 'kmz';
        } elseif ($extension === 'kml') {
            return 'kml';
        }
        
        throw new Exception('Unsupported file type');
    }

    /**
     * Extract coordinates from GPS file
     *
     * @param string $filePath
     * @param string $fileType
     * @return array
     */
    private function extractCoordinates($filePath, $fileType)
    {
        switch ($fileType) {
            case 'gpx':
                return $this->extractFromGpx($filePath);
            case 'kml':
                return $this->extractFromKml($filePath);
            case 'kmz':
                return $this->extractFromKmz($filePath);
            default:
                throw new Exception('Unsupported file type');
        }
    }

    /**
     * Extract coordinates from GPX file
     *
     * @param string $filePath
     * @return array
     */
    private function extractFromGpx($filePath)
    {
        $xml = new SimpleXMLElement(file_get_contents($filePath));
        $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
        
        $coordinates = [];
        
        // Try to get track points
        $trackPoints = $xml->xpath('//gpx:trkpt');
        
        if (empty($trackPoints)) {
            // If no track points, try route points
            $trackPoints = $xml->xpath('//gpx:rtept');
        }
        
        if (empty($trackPoints)) {
            // If no route points, try waypoints
            $trackPoints = $xml->xpath('//gpx:wpt');
        }
        
        foreach ($trackPoints as $point) {
            $lat = (float) $point['lat'];
            $lon = (float) $point['lon'];
            $ele = isset($point->ele) ? (float) $point->ele : 0;
            
            $coordinates[] = [
                'lat' => $lat,
                'lon' => $lon,
                'ele' => $ele
            ];
        }
        
        return $coordinates;
    }

    /**
     * Extract coordinates from KML file
     *
     * @param string $filePath
     * @return array
     */
    private function extractFromKml($filePath)
    {
        $xml = new SimpleXMLElement(file_get_contents($filePath));
        $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');
        
        $coordinates = [];
        
        // Try to get LineString coordinates
        $lineStrings = $xml->xpath('//kml:LineString/kml:coordinates');
        
        if (!empty($lineStrings)) {
            foreach ($lineStrings as $lineString) {
                $coords = $this->parseKmlCoordinates((string) $lineString);
                $coordinates = array_merge($coordinates, $coords);
            }
        }
        
        // Also check for Placemarks with Point coordinates
        $points = $xml->xpath('//kml:Point/kml:coordinates');
        
        if (!empty($points)) {
            foreach ($points as $point) {
                $coords = $this->parseKmlCoordinates((string) $point);
                $coordinates = array_merge($coordinates, $coords);
            }
        }
        
        return $coordinates;
    }

    /**
     * Extract coordinates from KMZ file
     *
     * @param string $filePath
     * @return array
     */
    private function extractFromKmz($filePath)
    {
        $tempDir = sys_get_temp_dir() . '/' . Uuid::uuid4()->toString();
        mkdir($tempDir);
        
        $zip = new ZipArchive();
        $result = $zip->open($filePath);
        
        if ($result !== true) {
            throw new Exception('Failed to open KMZ file');
        }
        
        $zip->extractTo($tempDir);
        $zip->close();
        
        // Find the KML file
        $kmlFile = null;
        $docKml = $tempDir . '/doc.kml';
        
        if (file_exists($docKml)) {
            $kmlFile = $docKml;
        } else {
            // Look for any KML file
            foreach (glob($tempDir . '/*.kml') as $file) {
                $kmlFile = $file;
                break;
            }
        }
        
        if (!$kmlFile) {
            throw new Exception('No KML file found in the KMZ archive');
        }
        
        $coordinates = $this->extractFromKml($kmlFile);
        
        // Clean up
        $this->deleteDirectory($tempDir);
        
        return $coordinates;
    }

    /**
     * Parse KML coordinates string
     *
     * @param string $coordinatesString
     * @return array
     */
    private function parseKmlCoordinates($coordinatesString)
    {
        $coordinates = [];
        $points = preg_split('/\s+/', trim($coordinatesString));
        
        foreach ($points as $point) {
            if (empty($point)) {
                continue;
            }
            
            $parts = explode(',', $point);
            
            if (count($parts) >= 2) {
                $lon = (float) $parts[0];
                $lat = (float) $parts[1];
                $ele = isset($parts[2]) ? (float) $parts[2] : 0;
                
                $coordinates[] = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'ele' => $ele
                ];
            }
        }
        
        return $coordinates;
    }

    /**
     * Convert coordinates to LineString
     *
     * @param array $coordinates
     * @return array
     */
    private function coordinatesToLineString($coordinates)
    {
        $lineString = [];
        
        foreach ($coordinates as $coordinate) {
            $lineString[] = [
                'lat' => $coordinate['lat'],
                'lon' => $coordinate['lon'],
                'ele' => $coordinate['ele']
            ];
        }
        
        return $lineString;
    }

    /**
     * Densify LineString to have points every specified distance
     *
     * @param array $lineString
     * @param float $targetDistance Distance in meters
     * @return array
     */
    private function densifyLineString($lineString, $targetDistance)
    {
        if (count($lineString) < 2) {
            return $lineString;
        }
        
        $densifiedLineString = [$lineString[0]];
        
        for ($i = 0; $i < count($lineString) - 1; $i++) {
            $startPoint = $lineString[$i];
            $endPoint = $lineString[$i + 1];
            
            $distance = $this->calculateDistanceBetweenPoints($startPoint, $endPoint);
            
            if ($distance <= $targetDistance) {
                // If segment is already shorter than target, add the end point
                $densifiedLineString[] = $endPoint;
                continue;
            }
            
            // Calculate how many points to insert
            $numSegments = ceil($distance / $targetDistance);
            $latStep = ($endPoint['lat'] - $startPoint['lat']) / $numSegments;
            $lonStep = ($endPoint['lon'] - $startPoint['lon']) / $numSegments;
            $eleStep = ($endPoint['ele'] - $startPoint['ele']) / $numSegments;
            
            // Add interpolated points
            for ($j = 1; $j < $numSegments; $j++) {
                $densifiedLineString[] = [
                    'lat' => $startPoint['lat'] + $j * $latStep,
                    'lon' => $startPoint['lon'] + $j * $lonStep,
                    'ele' => $startPoint['ele'] + $j * $eleStep
                ];
            }
            
            // Add the end point
            $densifiedLineString[] = $endPoint;
        }
        
        return $densifiedLineString;
    }

    /**
     * Calculate distance between two points using Haversine formula
     *
     * @param array $point1
     * @param array $point2
     * @return float Distance in meters
     */
    private function calculateDistanceBetweenPoints($point1, $point2)
    {
        $earthRadius = 6371000; // meters
        
        $lat1 = deg2rad($point1['lat']);
        $lon1 = deg2rad($point1['lon']);
        $lat2 = deg2rad($point2['lat']);
        $lon2 = deg2rad($point2['lon']);
        
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        
        $a = sin($dLat / 2) * sin($dLat / 2) + cos($lat1) * cos($lat2) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    /**
     * Calculate total distance of the LineString
     *
     * @param array $lineString
     * @return float Distance in meters
     */
    private function calculateTotalDistance($lineString)
    {
        $totalDistance = 0;
        
        for ($i = 0; $i < count($lineString) - 1; $i++) {
            $totalDistance += $this->calculateDistanceBetweenPoints($lineString[$i], $lineString[$i + 1]);
        }
        
        return $totalDistance;
    }

    /**
     * Calculate distance from start for each point in LineString
     *
     * @param array $lineString
     * @return array
     */
    private function calculateDistanceFromStart($lineString)
    {
        $distanceFromStart = [0]; // First point is at distance 0
        $cumulativeDistance = 0;
        
        for ($i = 0; $i < count($lineString) - 1; $i++) {
            $segmentDistance = $this->calculateDistanceBetweenPoints($lineString[$i], $lineString[$i + 1]);
            $cumulativeDistance += $segmentDistance;
            $distanceFromStart[] = $cumulativeDistance;
        }
        
        return $distanceFromStart;
    }

    /**
     * Delete directory and its contents recursively
     *
     * @param string $dir
     * @return bool
     */
    private function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
}
