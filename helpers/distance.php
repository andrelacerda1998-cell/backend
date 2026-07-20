<?php

if (! function_exists('calculate_distance')) {
    /**
     * Calculate the distance between two geographic coordinates.
     *
     * This function uses the Haversine formula to calculate the great-circle distance
     * between two points on the Earth specified by their latitude and longitude.
     * The default radius of the Earth is set to 6371 kilometers.
     * If the calculated distance is less than 1 kilometer, the function will return 1.
     *
     * @param  float  $latitudeFrom  The latitude of the starting point.
     * @param  float  $longitudeFrom  The longitude of the starting point.
     * @param  float  $latitudeTo  The latitude of the destination point.
     * @param  float  $longitudeTo  The longitude of the destination point.
     * @return int The calculated distance in kilometers.
     */
    function calculate_distance(float $latitudeFrom, float $longitudeFrom, float $latitudeTo, float $longitudeTo): int
    {
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos($latFrom) * cos($latTo) *
            sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = 6371 * $c;

        if ($distance < 1) {
            return 1;
        }

        return round($distance);
    }
}
