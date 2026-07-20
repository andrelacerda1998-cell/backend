<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\Gender;
use Illuminate\Support\Facades\Cache;

class GenderController extends Controller
{
    public function __invoke()
    {
        $genders = Cache::remember('genders', 86400, function () {
            return Gender::select(['id', 'name'])->get();
        });
        $lang = app()->getLocale();
        $genders = $genders->transform(function (Gender $gender) use ($lang) {
            $name = $gender->getTranslation('name', $lang);

            return [
                'id' => $gender->id,
                'name' => $name,
            ];
        });

        return new ApiSuccessResponse(compact('genders'));
    }
}
