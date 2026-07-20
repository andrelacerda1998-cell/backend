<?php

namespace App\Http\Services;

use Exception;

class UtilsService
{
    /**
     * @throws Exception
     */
    public static function searchAddress(array $address): array
    {
        // TODO perform address search based on zip code, zip code type and location and return the attributes that we need to create an address.

        if (empty([])) {
            throw new Exception('Address not found', 404);
        }

        return [];
    }
}
