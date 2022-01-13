<?php

namespace App\Task;

use App\Model\City;
use App\Model\CityWeatherJob;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\FieldType\DBDatetime;

class CityWeatherTask extends BuildTask
{

    public function run($request)
    {
        /** @var CityWeatherJob $job */
        $job = CityWeatherJob::create();
        $city = $job->nextCity();

        if (!$city) {
            Debug::dump('No records found.');

            return;
        }

        // Record it first seem, second write
        $job->CityID = $city->ID;
        $job->Title = $city->getTitle();
        $job->ExecuteInterval = $job->config()->get('defaultInterval');
        $job->ExecuteEvery = $job->config()->get('defaultPeriod');
        $job->write();

        Debug::dump(sprintf('Found City ID %d (%s)', $city->ID, $city->getTitle()));

        // When creating ScheduledExecutionJob it requires record ID
        $job->FirstExecution = DBDatetime::now()->Rfc2822();
        $job->write();
    }
}
