<?php
/**
 * Builds data/countries.json from the Natural Earth "Admin 0 – Countries" (1:50m) GeoJSON.
 *
 * The raw Natural Earth file is ~3 MB and has much more detail than a quiz needs, so this
 * script simplifies every outline, rounds coordinates and precomputes the nearest
 * countries for each country (used for the multiple choice options).
 *
 * Usage (only needed when you want to regenerate the data):
 *   curl -L -o ne_50m_admin_0_countries.geojson \
 *     https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_admin_0_countries.geojson
 *   php tools/build-data.php ne_50m_admin_0_countries.geojson
 *
 * Natural Earth data is public domain: https://www.naturalearthdata.com/about/terms-of-use/
 */

declare(strict_types=1);

// This is a command line tool: refuse to run when someone opens it in a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// How many nearby countries to store per country (the quiz picks 4 of them as wrong answers).
const NEARBY_COUNT = 12;

// Country types from Natural Earth that are asked about in the quiz.
// Everything else (small dependencies, Antarctica etc.) is still drawn as surrounding land.
const QUIZ_TYPES = ['Sovereign country', 'Country', 'Sovereignty', 'Disputed'];

// A few "Indeterminate" areas that are well known enough to be asked about.
const EXTRA_QUIZ_CODES = ['PSX', 'SAH'];

// Areas that should never be asked about even though their type would allow it.
const EXCLUDED_QUIZ_CODES = [
    'ATA', 'KAS', 'CYN', 'SOL', 'IOT', 'FLK',
    'JEY', 'GGY', 'IMN', 'ALD', 'ABW', 'CUW', 'SXM', 'MAC', 'HKG',
];

// Common short names that Natural Earth does not include, accepted when typing an answer.
const EXTRA_ALIASES = [
    'GBR' => ['UK', 'Great Britain', 'Britain', 'Förenade kungariket'],
    'USA' => ['US', 'America', 'Förenta staterna'],
    'COD' => ['DR Congo', 'DRC', 'Kongo Kinshasa', 'Demokratiska republiken Kongo'],
    'COG' => ['Congo', 'Republic of the Congo', 'Kongo', 'Kongo Brazzaville', 'Republiken Kongo'],
    'ARE' => ['UAE', 'Förenade Arabemiraten'],
    'CZE' => ['Tjeckiska republiken'],
    'PRK' => ['North Korea', 'Nordkorea'],
    'KOR' => ['South Korea', 'Sydkorea'],
    'NLD' => ['Holland'],
    'MMR' => ['Burma'],
    'TLS' => ['East Timor', 'Östtimor'],
    'CPV' => ['Cabo Verde'],
    'MKD' => ['Macedonia', 'Makedonien'],
    'BIH' => ['Bosnia', 'Bosnien'],
    'CAF' => ['CAR'],
];

$sourcePath = $argv[1] ?? null;
if ($sourcePath === null || !is_file($sourcePath)) {
    fwrite(STDERR, "Usage: php tools/build-data.php <ne_50m_admin_0_countries.geojson>\n");
    exit(1);
}

$geoJson = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);

$countries = [];
foreach ($geoJson['features'] as $feature) {
    $properties = $feature['properties'];
    $code = $properties['ADM0_A3'];

    // Normalise Polygon / MultiPolygon to a list of polygons (each a list of rings).
    $polygons = $feature['geometry']['type'] === 'Polygon'
        ? [$feature['geometry']['coordinates']]
        : $feature['geometry']['coordinates'];

    // Small countries need a finer tolerance, otherwise they disappear completely.
    $rawBoundingBox = boundingBoxOfPolygons($polygons);
    $sizeInDegrees = max($rawBoundingBox[2] - $rawBoundingBox[0], $rawBoundingBox[3] - $rawBoundingBox[1]);
    $tolerance = min(0.03, max(0.0005, $sizeInDegrees / 300));

    $simplifiedRings = [];
    foreach ($polygons as $polygon) {
        // Only the outer ring is kept: lakes/holes are not needed to recognise a country.
        $outerRing = $polygon[0];
        $simplified = simplifyRing($outerRing, $tolerance);
        if (count($simplified) >= 4) {
            $simplifiedRings[] = $simplified;
        }
    }
    // Never end up with an empty country: fall back to the largest original ring.
    if ($simplifiedRings === []) {
        $simplifiedRings[] = roundRing($polygons[0][0]);
    }

    $simplifiedRings = unwrapRingsAroundMainland($simplifiedRings);

    $isQuizCountry = (in_array($properties['TYPE'], QUIZ_TYPES, true) || in_array($code, EXTRA_QUIZ_CODES, true))
        && !in_array($code, EXCLUDED_QUIZ_CODES, true);

    $countries[$code] = [
        'code' => $code,
        'nameEn' => $properties['NAME_EN'],
        'nameSv' => $properties['NAME_SV'],
        // Extra names that are accepted as correct when typing (e.g. "USA", "Czech Republic").
        'aliases' => array_values(array_unique(array_filter([
            $properties['NAME'],
            $properties['NAME_LONG'],
            $properties['NAME_ALT'] ?? null,
            $properties['NAME_SORT'],
            $properties['NAME_CIAWF'] ?? null,
            ...(EXTRA_ALIASES[$code] ?? []),
        ]))),
        'continent' => $properties['CONTINENT'],
        'quiz' => $isQuizCountry,
        // Full extent (used to find surrounding countries) and the "focus" extent the map zooms
        // to, which leaves out far away overseas territories (French Guiana, Réunion ...).
        'bbox' => boundingBoxOfRings($simplifiedRings),
        'focus' => boundingBoxOfRings(focusRings($simplifiedRings)),
        'rings' => $simplifiedRings,
    ];
}

// Precompute the nearest countries (by shortest distance between outlines).
foreach ($countries as $code => $country) {
    $countries[$code]['nearby'] = findNearbyCountries($country, $countries, NEARBY_COUNT);
}

$outputPath = __DIR__ . '/../data/countries.json';
file_put_contents($outputPath, json_encode(array_values($countries), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

$quizCount = count(array_filter($countries, static fn (array $country): bool => $country['quiz']));
printf("Wrote %d countries (%d in the quiz) to %s (%d KB)\n", count($countries), $quizCount, realpath($outputPath), filesize($outputPath) / 1024);

/* -------------------------------------------------------------------------- */

/**
 * Douglas–Peucker simplification of a closed ring, followed by rounding to 3 decimals (~100 m).
 */
function simplifyRing(array $ring, float $tolerance): array
{
    $simplified = douglasPeucker($ring, $tolerance);
    return roundRing($simplified);
}

function roundRing(array $ring): array
{
    $rounded = [];
    foreach ($ring as $point) {
        $roundedPoint = [round($point[0], 3), round($point[1], 3)];
        // Skip points that became duplicates after rounding.
        if ($rounded === [] || end($rounded) !== $roundedPoint) {
            $rounded[] = $roundedPoint;
        }
    }
    return $rounded;
}

function douglasPeucker(array $points, float $tolerance): array
{
    $pointCount = count($points);
    if ($pointCount < 3) {
        return $points;
    }

    // Iterative version (recursion can get deep on long coastlines).
    $keepPoint = array_fill(0, $pointCount, false);
    $keepPoint[0] = true;
    $keepPoint[$pointCount - 1] = true;
    $segmentStack = [[0, $pointCount - 1]];

    while ($segmentStack !== []) {
        [$startIndex, $endIndex] = array_pop($segmentStack);
        $largestDistance = 0.0;
        $largestIndex = -1;
        for ($index = $startIndex + 1; $index < $endIndex; $index++) {
            $distance = distanceToSegment($points[$index], $points[$startIndex], $points[$endIndex]);
            if ($distance > $largestDistance) {
                $largestDistance = $distance;
                $largestIndex = $index;
            }
        }
        if ($largestIndex !== -1 && $largestDistance > $tolerance) {
            $keepPoint[$largestIndex] = true;
            $segmentStack[] = [$startIndex, $largestIndex];
            $segmentStack[] = [$largestIndex, $endIndex];
        }
    }

    $result = [];
    foreach ($points as $index => $point) {
        if ($keepPoint[$index]) {
            $result[] = $point;
        }
    }
    return $result;
}

function distanceToSegment(array $point, array $segmentStart, array $segmentEnd): float
{
    $deltaX = $segmentEnd[0] - $segmentStart[0];
    $deltaY = $segmentEnd[1] - $segmentStart[1];
    $lengthSquared = $deltaX * $deltaX + $deltaY * $deltaY;
    if ($lengthSquared == 0.0) {
        return hypot($point[0] - $segmentStart[0], $point[1] - $segmentStart[1]);
    }
    $projection = (($point[0] - $segmentStart[0]) * $deltaX + ($point[1] - $segmentStart[1]) * $deltaY) / $lengthSquared;
    $projection = max(0.0, min(1.0, $projection));
    return hypot($point[0] - ($segmentStart[0] + $projection * $deltaX), $point[1] - ($segmentStart[1] + $projection * $deltaY));
}

/**
 * Countries that cross the 180° meridian (Russia, Fiji, USA/Alaska ...) would otherwise get a
 * bounding box covering the whole world. Rings are shifted by ±360° so that they sit next to
 * the largest ring ("mainland"), which gives a compact bounding box (longitudes may exceed 180).
 */
function unwrapRingsAroundMainland(array $rings): array
{
    $mainlandCenterLongitude = averageLongitude($rings[largestRingIndex($rings)]);

    foreach ($rings as $index => $ring) {
        $shift = 0;
        $ringCenterLongitude = averageLongitude($ring);
        if ($ringCenterLongitude - $mainlandCenterLongitude > 180) {
            $shift = -360;
        } elseif ($mainlandCenterLongitude - $ringCenterLongitude > 180) {
            $shift = 360;
        }
        if ($shift !== 0) {
            $rings[$index] = array_map(static fn (array $point): array => [$point[0] + $shift, $point[1]], $ring);
        }
    }
    return $rings;
}

/**
 * Index of the ring with the largest real-world area (the "mainland").
 */
function largestRingIndex(array $rings): int
{
    $largestIndex = 0;
    $largestArea = -1.0;
    foreach ($rings as $index => $ring) {
        $area = approximateRingArea($ring);
        if ($area > $largestArea) {
            $largestArea = $area;
            $largestIndex = $index;
        }
    }
    return $largestIndex;
}

/**
 * Shoelace formula in degrees, with longitudes scaled by cos(latitude) so that
 * areas near the poles are not exaggerated.
 */
function approximateRingArea(array $ring): float
{
    $area = 0.0;
    $pointCount = count($ring);
    for ($index = 0; $index < $pointCount; $index++) {
        [$currentLongitude, $currentLatitude] = $ring[$index];
        [$nextLongitude, $nextLatitude] = $ring[($index + 1) % $pointCount];
        $area += $currentLongitude * $nextLatitude - $nextLongitude * $currentLatitude;
    }
    $averageLatitude = array_sum(array_column($ring, 1)) / $pointCount;
    return abs($area / 2) * cos(deg2rad($averageLatitude));
}

/**
 * The rings that belong to the "core" of a country: the mainland plus every ring that lies
 * within one mainland-size (at least 10°) of it. Far away overseas territories are left out.
 */
function focusRings(array $rings): array
{
    $mainlandBox = boundingBoxOfRings([$rings[largestRingIndex($rings)]]);
    $mainlandSize = max($mainlandBox[2] - $mainlandBox[0], $mainlandBox[3] - $mainlandBox[1]);
    $maxGap = max(10.0, $mainlandSize);

    return array_values(array_filter(
        $rings,
        static fn (array $ring): bool => boundingBoxDistance($mainlandBox, boundingBoxOfRings([$ring])) <= $maxGap
    ));
}

function averageLongitude(array $ring): float
{
    return array_sum(array_column($ring, 0)) / count($ring);
}

function boundingBoxOfPolygons(array $polygons): array
{
    return boundingBoxOfRings(array_map(static fn (array $polygon): array => $polygon[0], $polygons));
}

/** @return array{float, float, float, float} [minLongitude, minLatitude, maxLongitude, maxLatitude] */
function boundingBoxOfRings(array $rings): array
{
    $minLongitude = $minLatitude = INF;
    $maxLongitude = $maxLatitude = -INF;
    foreach ($rings as $ring) {
        foreach ($ring as [$longitude, $latitude]) {
            $minLongitude = min($minLongitude, $longitude);
            $maxLongitude = max($maxLongitude, $longitude);
            $minLatitude = min($minLatitude, $latitude);
            $maxLatitude = max($maxLatitude, $latitude);
        }
    }
    return [$minLongitude, $minLatitude, $maxLongitude, $maxLatitude];
}

/**
 * Returns the codes of the closest quiz countries, closest first.
 * Uses a cheap bounding box distance to pick candidates, then the closest pair of outline points.
 */
function findNearbyCountries(array $country, array $allCountries, int $maxCount): array
{
    $candidates = [];
    foreach ($allCountries as $otherCode => $otherCountry) {
        if ($otherCode === $country['code'] || !$otherCountry['quiz']) {
            continue;
        }
        $candidates[$otherCode] = boundingBoxDistance($country['focus'], $otherCountry['bbox']);
    }
    asort($candidates);
    $candidates = array_slice($candidates, 0, $maxCount * 3, true);

    $distances = [];
    foreach (array_keys($candidates) as $otherCode) {
        $distances[$otherCode] = closestOutlineDistance(focusRings($country['rings']), $allCountries[$otherCode]['rings']);
    }
    asort($distances);
    return array_slice(array_keys($distances), 0, $maxCount);
}

function boundingBoxDistance(array $firstBox, array $secondBox): float
{
    $smallestDistance = INF;
    // Try the second box shifted around the globe too (for countries near the 180° meridian).
    foreach ([-360, 0, 360] as $shift) {
        $gapLongitude = max(0, $secondBox[0] + $shift - $firstBox[2], $firstBox[0] - ($secondBox[2] + $shift));
        $gapLatitude = max(0, $secondBox[1] - $firstBox[3], $firstBox[1] - $secondBox[3]);
        $smallestDistance = min($smallestDistance, hypot($gapLongitude, $gapLatitude));
    }
    return $smallestDistance;
}

function closestOutlineDistance(array $firstRings, array $secondRings): float
{
    // Thin out the points a bit to keep the build fast; precision is not critical here.
    $firstPoints = samplePoints($firstRings, 400);
    $secondPoints = samplePoints($secondRings, 400);
    $smallestDistance = INF;
    foreach ($firstPoints as [$firstLongitude, $firstLatitude]) {
        $longitudeScale = cos(deg2rad($firstLatitude));
        foreach ($secondPoints as [$secondLongitude, $secondLatitude]) {
            $longitudeGap = abs($firstLongitude - $secondLongitude);
            $longitudeGap = fmod($longitudeGap, 360);
            if ($longitudeGap > 180) {
                $longitudeGap = 360 - $longitudeGap;
            }
            $distance = ($longitudeGap * $longitudeScale) ** 2 + ($firstLatitude - $secondLatitude) ** 2;
            if ($distance < $smallestDistance) {
                $smallestDistance = $distance;
            }
        }
    }
    return sqrt($smallestDistance);
}

function samplePoints(array $rings, int $maxPoints): array
{
    $allPoints = array_merge(...$rings);
    $step = max(1, (int) ceil(count($allPoints) / $maxPoints));
    $sampled = [];
    for ($index = 0; $index < count($allPoints); $index += $step) {
        $sampled[] = $allPoints[$index];
    }
    return $sampled;
}
