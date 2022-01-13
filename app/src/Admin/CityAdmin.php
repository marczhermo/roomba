<?php

namespace App\Admin;

use App\Model\City;
use App\Model\CityWeatherJob;
use SilverStripe\Admin\ModelAdmin;

class CityAdmin extends ModelAdmin
{

    private static string $url_segment = 'city-weather';

    private static string $menu_title = 'City Weather';

    private static string $menu_icon_class = 'font-icon-checklist';

    private static array $managed_models = [
        CityWeatherJob::class => [
            'dataClass' => CityWeatherJob::class,
            'title' => 'Weather'
        ],
        City::class => [
            'dataClass' => City::class,
            'title' => 'City'
        ],
    ];

}
