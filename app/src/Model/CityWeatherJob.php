<?php

namespace App\Model;

use App\Service\WeatherService;
use Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Extensions\ScheduledExecutionExtension;

class CityWeatherJob extends DataObject
{

    private static string $table_name = 'CityWeatherJob';

    private static array $db = [
        'Title' => 'Varchar(200)',
        'Message' => 'Text', // Optional
        'PreviousID' => 'Int', // Optional
        'Status' => 'Enum("Queued,Running,Broken,Stopped", "Queued")', // Optional
    ];

    private static int $defaultInterval = 1; // 1 minute

    private static string $defaultPeriod = 'Minute'; // Minute,Hour,Day,Week,Fortnight,Month,Year

    private static array $has_one = [
        'City' => City::class
    ];

    private static array $extensions = [
        ScheduledExecutionExtension::class
    ];

    private static array $summary_fields = [
        'Title',
        'PreviousID',
        'CityID',
        'Status',
    ];

    public function onBeforeWrite()
    {
        if (!$this->CityID) {
            $city = $this->nextCity();

            if ($city) {
                $this->CityID = $city->ID;
                $this->Title = $city->getTitle();
            }
        }

        if (!$this->ExecuteInterval) {
            $this->ExecuteInterval = $this->config()->get('defaultInterval');
        }

        if (!$this->ExecuteEvery) {
            $this->ExecuteEvery = $this->config()->get('defaultPeriod');
        }

        // Intentionally put below for extensions to read during their own onBeforeWrite
        parent::onBeforeWrite();
    }

    /**
     * Determines the next higher ID to process from the table.
     */
    public function nextCity(): ?DataObject
    {
        $cities = City::get();
        $maxID = $this->CityID;
        $excludeIDs = self::get()->column('CityID');

        if ($excludeIDs) {
            $cities = $cities->exclude('ID', $excludeIDs);
            $maxID = max($excludeIDs);
        }

        return $cities
            ->where(['"ID" > ?' => (int) $maxID])
            ->sort('City.ID ASC')
            ->limit(1, 0)
            ->first();
    }

    public function processJob()
    {
        $service = Injector::inst()->create(WeatherService::class);
        $city = $this->City();

        $weather = $service->getData($city->Name, ['1T'=>'']);
        $city->Weather = $weather;

        sleep(1); // be kind to 3rd party apis

        $temperature = $service->getData($city->Name, ['format'=>'3']);
        $city->Temperature = $temperature;

        // City::class
        $city->write();

        // CityWeatherJob::class
        $this->Message = $weather;
        $this->write();

        Debug::dump($weather);
    }

    /**
     * Stops the rescheduling of queue job
     * @param string $status
     * @param string $message
     *
     * @return $this
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function stopSchedule($status = 'Stopped', $message = '')
    {
        $this->FirstExecution = ''; // onBeforeWrite checks of ScheduledExecutionExtension
        $this->ExecuteEvery = '';   // rescheduling logic of ScheduledExecutionJob
        $this->Status = $status;
        $this->Message = $message;
        $this->write();

        return $this;
    }

    /**
     * Interface with ScheduledExecutionExtension and executed by ScheduledExecutionJob
     *
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function onScheduledExecution(): self
    {
        ini_set('max_execution_time', 0);

        try {
            $this->Status = 'Running';
            $this->FirstExecution = ''; //prevents duplicate job creation
            $this->write();

            // Actual work you wanted to do
            $this->processJob();
        } catch (Exception $exception) {
            return $this->stopSchedule('Broken', $exception->getMessage());
        }

        // Optional: Remember the last city that was processed before processing a new city
        $this->PreviousID = $this->CityID;

        // Get the next city to process
        $city = $this->nextCity();

        if ($city) {
            $this->Status = 'Queued';
            $this->CityID = $city->ID;
            $this->Title = $city->getTitle();
            $this->write();

            return $this;
        }

        return $this->stopSchedule('Stopped', 'No more records to process');
    }

}
