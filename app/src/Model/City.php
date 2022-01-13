<?php

namespace App\Model;

use SilverStripe\ORM\DataObject;

class City extends DataObject
{

    private static string $table_name = 'City';

    private static array $db = [
        'Name' => 'Varchar(200)',
        'Lat' => 'Decimal(7,4)',
        'Lang' => 'Decimal(7,4)',
        'Country' => 'Varchar(200)',
        'Population' => 'Int',
        'Weather' => 'Text',
    ];

    public function getTitle()
    {
        return $this->Name . ', ' . $this->Country;
    }
}
