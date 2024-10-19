<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use function PHPSTORM_META\map;

class LocationController extends Controller
{
    public function countries(Request $request)
    {
        /* GET ALL COUNTRIES */
        $countries = Country::all()
                            ->map(function ($country) {
                                return [
                                    'id' => $country->id,
                                    'label' => $country->name,
                                    'value' => $country->name,
                                ];
                            });
        /* GET ALL COUNTRIES */

        return response()->json(['result' => 'success', 'countries' => $countries]);
    }

    public function states(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'country_id' => ['required','integer'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GET STATES */
        $states = State::where('country_id', $request->country_id)
                       ->get()
                       ->map(function ($state) {
                            return [
                                'id' => $state->id,
                                'label' => $state->name,
                                'value' => $state->name,
                            ];
                       });
        /* GET STATES */

        return response()->json(['result' => 'success', 'states' => $states]);
    }

    public function cities(Request $request)
    {
        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'city_id' => ['required','integer'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'failed', 'message' => $validator->messages()], 422);
        /* VALIDATOR */

        /* GET STATES */
        $cities = City::where('state_id', $request->city_id)
                       ->get()
                       ->map(function ($city) {
                            return [
                                'id' => $city->id,
                                'label' => $city->name,
                                'value' => $city->name,
                            ];
                       });
        /* GET STATES */

        return response()->json(['result' => 'success', 'cities' => $cities]);
    }
}
