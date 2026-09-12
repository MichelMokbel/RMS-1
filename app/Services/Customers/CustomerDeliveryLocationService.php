<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerDeliveryLocationService
{
    private const EXPECTED_BOUNDARY_SHA256 = '9afaa263f5f2385de40208af9ea0de55a5bc8d9384df241efd7be86b1793eaca';

    /** @var array<int, array<int, array<int, array{0:float,1:float}>>>|null */
    private static ?array $polygons = null;

    /**
     * @param  array<string, mixed>  $location
     * @return array{latitude:string,longitude:string,place_id:string|null,building:string,unit:string|null,instructions:string|null}
     */
    public function normalize(array $location): array
    {
        $latitude = $this->normalizeCoordinate($location['latitude'] ?? null, -90, 90, 'delivery_location.latitude');
        $longitude = $this->normalizeCoordinate($location['longitude'] ?? null, -180, 180, 'delivery_location.longitude');
        $building = trim((string) ($location['building'] ?? ''));

        if ($building === '') {
            throw ValidationException::withMessages([
                'delivery_location.building' => __('Enter your building or villa details.'),
            ]);
        }

        if (! $this->contains((float) $latitude, (float) $longitude)) {
            throw ValidationException::withMessages([
                'delivery_location.latitude' => __('Choose a location inside Qatar.'),
            ]);
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'place_id' => $this->nullableTrimmed($location['place_id'] ?? null),
            'building' => $building,
            'unit' => $this->nullableTrimmed($location['unit'] ?? null),
            'instructions' => $this->nullableTrimmed($location['instructions'] ?? null),
        ];
    }

    /**
     * @param  array{latitude:string,longitude:string,place_id:string|null,building:string,unit:string|null,instructions:string|null}  $location
     * @return array<string, mixed>
     */
    public function portalAttributes(array $location): array
    {
        return [
            'portal_delivery_address' => $this->summary($location),
            'portal_delivery_latitude' => $location['latitude'],
            'portal_delivery_longitude' => $location['longitude'],
            'portal_delivery_place_id' => $location['place_id'],
            'portal_delivery_building' => $location['building'],
            'portal_delivery_unit' => $location['unit'],
            'portal_delivery_instructions' => $location['instructions'],
        ];
    }

    /** @return array<string, mixed> */
    public function customerAttributesFromUser(User $user): array
    {
        if ($user->portal_delivery_latitude === null || $user->portal_delivery_longitude === null) {
            return [];
        }

        return [
            'delivery_address' => $user->portal_delivery_address,
            'delivery_latitude' => $user->portal_delivery_latitude,
            'delivery_longitude' => $user->portal_delivery_longitude,
            'delivery_place_id' => $user->portal_delivery_place_id,
            'delivery_building' => $user->portal_delivery_building,
            'delivery_unit' => $user->portal_delivery_unit,
            'delivery_instructions' => $user->portal_delivery_instructions,
        ];
    }

    /** @return array<string, null> */
    public function clearedCustomerLocationAttributes(): array
    {
        return [
            'delivery_latitude' => null,
            'delivery_longitude' => null,
            'delivery_place_id' => null,
            'delivery_building' => null,
            'delivery_unit' => null,
            'delivery_instructions' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function serialize(User $user, ?Customer $customer): ?array
    {
        $prefix = $customer ? 'delivery_' : 'portal_delivery_';
        $source = $customer ?? $user;
        $latitude = $source->getAttribute($prefix.'latitude');
        $longitude = $source->getAttribute($prefix.'longitude');

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => (string) $latitude,
            'longitude' => (string) $longitude,
            'building' => $source->getAttribute($prefix.'building'),
            'unit' => $source->getAttribute($prefix.'unit'),
            'instructions' => $source->getAttribute($prefix.'instructions'),
            'maps_url' => $this->mapsUrl((string) $latitude, (string) $longitude),
        ];
    }

    public function contains(float $latitude, float $longitude): bool
    {
        if (! is_finite($latitude) || ! is_finite($longitude)) {
            return false;
        }

        foreach ($this->polygons() as $polygon) {
            $outer = $polygon[0] ?? [];
            if ($this->ringRelation($longitude, $latitude, $outer) < 0) {
                continue;
            }

            foreach (array_slice($polygon, 1) as $hole) {
                if ($this->ringRelation($longitude, $latitude, $hole) >= 0) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array{latitude:string,longitude:string,place_id:string|null,building:string,unit:string|null,instructions:string|null}  $location
     */
    private function summary(array $location): string
    {
        $parts = [$location['building']];
        if ($location['unit'] !== null) {
            $parts[] = $location['unit'];
        }
        if ($location['instructions'] !== null) {
            $parts[] = $location['instructions'];
        }
        $parts[] = $this->mapsUrl($location['latitude'], $location['longitude']);

        return implode(', ', $parts);
    }

    private function mapsUrl(string $latitude, string $longitude): string
    {
        return 'https://www.google.com/maps?q='.$latitude.','.$longitude;
    }

    private function normalizeCoordinate(mixed $value, float $minimum, float $maximum, string $field): string
    {
        if (is_array($value) || is_object($value) || is_bool($value) || $value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([$field => __('Choose a valid map location.')]);
        }

        $number = (float) $value;
        if (! is_finite($number) || $number < $minimum || $number > $maximum) {
            throw ValidationException::withMessages([$field => __('Choose a valid map location.')]);
        }

        return number_format($number, 6, '.', '');
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @return array<int, array<int, array<int, array{0:float,1:float}>>> */
    private function polygons(): array
    {
        if (self::$polygons !== null) {
            return self::$polygons;
        }

        $path = resource_path('geo/qatar-adm0.geojson');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($contents) || hash('sha256', $contents) !== self::EXPECTED_BOUNDARY_SHA256) {
            throw new RuntimeException('The Qatar delivery boundary is unavailable or invalid.');
        }

        $geoJson = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $geometry = $geoJson['features'][0]['geometry'] ?? null;
        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'MultiPolygon' || ! is_array($geometry['coordinates'] ?? null)) {
            throw new RuntimeException('The Qatar delivery boundary has an unsupported geometry.');
        }

        self::$polygons = $geometry['coordinates'];

        return self::$polygons;
    }

    /**
     * Return 1 for inside, 0 for an edge, and -1 for outside.
     *
     * @param  array<int, array{0:float,1:float}>  $ring
     */
    private function ringRelation(float $x, float $y, array $ring): int
    {
        $inside = false;
        $count = count($ring);
        if ($count < 4) {
            return -1;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ($this->pointOnSegment($x, $y, (float) $xi, (float) $yi, (float) $xj, (float) $yj)) {
                return 0;
            }
            if (((float) $yi > $y) !== ((float) $yj > $y)
                && $x < ((float) $xj - (float) $xi) * ($y - (float) $yi) / ((float) $yj - (float) $yi) + (float) $xi) {
                $inside = ! $inside;
            }
        }

        return $inside ? 1 : -1;
    }

    private function pointOnSegment(float $x, float $y, float $ax, float $ay, float $bx, float $by): bool
    {
        $lengthSquared = ($bx - $ax) ** 2 + ($by - $ay) ** 2;
        if ($lengthSquared < 0.000000000000001) {
            return abs($x - $ax) <= 0.000000001 && abs($y - $ay) <= 0.000000001;
        }

        $cross = ($x - $ax) * ($by - $ay) - ($y - $ay) * ($bx - $ax);
        if (abs($cross) > 0.000000001) {
            return false;
        }

        $dot = ($x - $ax) * ($bx - $ax) + ($y - $ay) * ($by - $ay);
        if ($dot < 0) {
            return false;
        }

        return $dot <= $lengthSquared;
    }
}
