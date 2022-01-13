<?php

namespace App\Service;

use Exception;
use GuzzleHttp\Client;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;


class WeatherService
{

    use Configurable;
    use Injectable;

    /** @see `SS_WEATHER_API_ENDPOINT` */
    private ?string $baseURL;

    private static int $timeout = 5;

    public function __construct(?string $baseURL)
    {
        $this->baseURL = $baseURL;
    }

    public function getBaseURL(): string
    {
        return $this->baseURL;
    }

    /**
     * @throws Exception|\GuzzleHttp\Exception\GuzzleException
     */
    public function getData(string $path, array $params = []): string
    {
        $client = new Client([
            'base_uri' => $this->getBaseURL(),
            'timeout'  => $this->config()->get('timeout'),
        ]);

        $response = $client->request(
            'GET',
            $path,
            [
                'query' => $params,
                'curl' => [CURLOPT_SSL_VERIFYPEER => false],
                'headers' => ['Accept' => 'text/plain'],
            ]
        );

        return trim($response->getBody()->getContents());
    }

}
