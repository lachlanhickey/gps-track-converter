# GPS Track Converter

A PHP package for converting GPX, KMZ, and KML files to LineString format with distance calculations and route densification.

## Features

- Convert GPX, KMZ, and KML files to LineString format
- Automatic file type detection
- Densify LineString to have points at regular intervals (default: 100 meters)
- Calculate total distance of the track
- Calculate distance from start for each point
- Compatible with Laravel and other PHP frameworks

## Requirements

- PHP 7.4 or 8.0+
- SimpleXML extension
- ZIP extension
- Composer

## Installation

```bash
composer require lachlanhickey/gps-track-converter
```

Or add this to your `composer.json` and run `composer install`:

```json
{
    "require": {
        "lachlanhickey/gps-track-converter": "^1.0"
    }
}
```

## Usage

### Basic Usage

```php
use App\GpsTrackConverter;

$converter = new GpsTrackConverter();
$result = $converter->convert('/path/to/track.gpx');

// Access results
$points = $result->points; // Array of point objects
$totalDistance = $result->totalDistance; // in meters

// Access start and finish locations directly
$startPoint = $result->start_location;
$finishPoint = $result->finish_location;

echo "Route starts at: {$startPoint->lat}, {$startPoint->lon}";
echo "Route ends at: {$finishPoint->lat}, {$finishPoint->lon}";
echo "Total distance: {$result->totalDistance} meters";

// Access individual point data
$firstPoint = $points[0];
echo "First point: Lat: {$firstPoint->lat}, Lon: {$firstPoint->lon}, " .
     "Elevation: {$firstPoint->elevation}, " .
     "Distance from start: {$firstPoint->distance_from_start} meters";
```

### Laravel Controller Example

```php
namespace App\Http\Controllers;

use App\GpsTrackConverter;
use Illuminate\Http\Request;

class GpsTrackController extends Controller
{
    public function convert(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:gpx,kml,kmz',
        ]);

        $file = $request->file('file');
        $filePath = $file->getPathname();
        $fileType = $file->getClientOriginalExtension();
        
        try {
            $converter = new GpsTrackConverter();
            $result = $converter->convert($filePath, $fileType);
            
            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
```

## Return Format

The converter returns an object with the following structure:

```php
stdClass Object (
    [points] => Array (
        [0] => stdClass Object (
            [lat] => 47.123456
            [lon] => 8.123456
            [elevation] => 1234.5
            [distance_from_start] => 0 // in meters
        )
        [1] => stdClass Object (
            [lat] => 47.124567
            [lon] => 8.124567
            [elevation] => 1235.5
            [distance_from_start] => 100.0 // in meters
        )
        // More points...
    )
    [totalDistance] => 12345.67 // Total distance in meters
    [originalPointCount] => 120
    [densifiedPointCount] => 245
    [start_location] => stdClass Object (
        [lat] => 47.123456
        [lon] => 8.123456
        [elevation] => 1234.5
        [distance_from_start] => 0
    )
    [finish_location] => stdClass Object (
        [lat] => 47.129876
        [lon] => 8.129876
        [elevation] => 1240.5
        [distance_from_start] => 12345.67
    )
)
```

## Supported File Formats

### GPX

Extracts coordinates from:
- Track points (`trkpt`)
- Route points (`rtept`)
- Waypoints (`wpt`)

### KML

Extracts coordinates from:
- LineString elements
- Point elements

### KMZ

Automatically extracts and processes the KML file from the KMZ archive.

## Densification

The package can densify routes to have points at regular intervals. This is useful for:
- Creating smoother visualizations
- Ensuring consistent data for analysis
- Generating intermediate points for animations

By default, densification creates points every 100 meters along the route.

## Error Handling

The converter throws exceptions for:
- Unsupported file types
- Files with no coordinates
- Invalid file formats
- KMZ files without a KML inside

## License

MIT

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.