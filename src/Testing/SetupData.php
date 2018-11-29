<?php

namespace Conduit\Testing;

use Geocoder\Model\Bounds;
use Geocoder\Model\Address;
use Geocoder\Model\Country;
use Geocoder\Model\AdminLevel;
use Geocoder\Model\Coordinates;
use Geocoder\Model\AddressCollection;
use Geocoder\Laravel\Facades\Geocoder;
use Geocoder\Model\AdminLevelCollection;
use Geocoder\Provider\GoogleMaps\Model\GoogleAddress;

trait SetupData
{
    /**
     * @var array
     */
    protected $models = [];
    
    /**
     * @var array
     */
    protected $guzzleResponses = [];
    
    /**
     * @var string
     */
    protected $token;
    
    /**
     * @var array
     */
    protected $address = [
        'street_number' => 916,
        'street_name' => 'Shellbrook Court',
        'unit_number' => 4,
        'unit_type' => 'Apartment',
        'city' => 'Raleigh',
        'zip_code' => 27609,
        'state' => [
            'name' => 'North Carolina',
            'abbreviation' => 'NC'
        ]
    ];
    
    /**
     * Mock a Geocode response
     */
    protected function _mockGeolocation()
    {
        $fullAddress = $this->address['street_number'] . ' '
            . $this->address['street_name'] . ' '
            . (!empty($this->address['unit_type']) ? $this->address['unit_type'] . ' ' : '')
            . (!empty($this->address['unit_number']) ? $this->address['unit_number'] . ', ' : '')
            . $this->address['city'] . ', '
            . $this->address['state']['abbreviation'] . ' '
            . $this->address['zip_code'];
        
        $this->address['latitude'] = !empty($this->address['latitude']) ? $this->address['latitude'] : 35.858733;
        $this->address['longitude'] = !empty($this->address['longitude']) ? $this->address['longitude'] : -78.6537419;
        $coordinates = [
            'latitude' => $this->address['latitude'],
            'longitude' => $this->address['longitude']
        ];
        $coordinates = app(Coordinates::class, $coordinates);
        
        $bounds = [
            'south' => $coordinates->getLatitude(),
            'west' => $coordinates->getLongitude(),
            'north' => $coordinates->getLatitude(),
            'east' => $coordinates->getLongitude()
        ];
        $bounds = app(Bounds::class, $bounds);
        
        $this->address['county'] = !empty($this->address['county']) ? $this->address['county'] : 'Wake County';
        $adminLevels = collect([
            [
                'level' => 1,
                'name' => $this->address['state']['name'],
                'code' => $this->address['state']['abbreviation']
            ],
            [
                'level' => 2,
                'name' => $this->address['county'],
                'code' => $this->address['county']
            ],
            [
                'level' => 3,
                'name' => 'House Creek',
                'code' => 'House Creek'
            ]
        ])->map(function ($adminLevel) {
            return app(AdminLevel::class, $adminLevel);
        })->toArray();
        $adminLevelCollection = [
            'adminLevels' => $adminLevels
        ];
        $adminLevelCollection = app(AdminLevelCollection::class, $adminLevelCollection);
        
        $country = [
            'name' => 'United States',
            'code' => 'US'
        ];
        $country = app(Country::class, $country);
        
        $address = [
            'providedBy' => 'n/a',
            'adminLevels' => $adminLevelCollection,
            'coordinates' => $coordinates,
            'bounds' => $bounds,
            'streetNumber' => $this->address['street_number'],
            'streetName' => $this->address['street_name'],
            'postalCode' => $this->address['zip_code'],
            'locality' => $this->address['city'],
            'subLocality' => null,
            'country' => $country,
            'timezone' => null
        ];
        $address = app(Address::class, $address);
        
        $addressCollection = [
            'addresses' => [
                $address
            ]
        ];
        $addressCollection = app(AddressCollection::class, $addressCollection);
        
        Geocoder::shouldReceive('geocode->get')->once()->andReturn($addressCollection);
    
        $this->guzzleResponses['geolocation'] = [
            'results' => [
                [
                    'address_components' => [
                        [
                            'long_name' => (string)$this->address['street_number'],
                            'short_name' => (string)$this->address['street_number'],
                            'types' => [
                                'street_number'
                            ]
                        ],
                        [
                            'long_name' => $this->address['street_name'],
                            'short_name' => $this->address['street_name'],
                            'types' => [
                                'route'
                            ]
                        ],
                        [
                            'long_name' => $this->address['city'],  //North Raleigh
                            'short_name' => $this->address['city'],  //North Raleigh
                            'types' => [
                                'neighborhood',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => $this->address['city'],
                            'short_name' => $this->address['city'],
                            'types' => [
                                'locality',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => 'House Creek',
                            'short_name' => 'House Creek',
                            'types' => [
                                'administrative_area_level_3',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => $this->address['county'],
                            'short_name' => $this->address['county'],
                            'types' => [
                                'administrative_area_level_2',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => $this->address['state']['name'],
                            'short_name' => $this->address['state']['abbreviation'],
                            'types' => [
                                'administrative_area_level_1',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => 'United States',
                            'short_name' => 'US',
                            'types' => [
                                'country',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => (string)$this->address['zip_code'],
                            'short_name' => (string)$this->address['zip_code'],
                            'types' => [
                                'postal_code'
                            ]
                        ]
                    ],
                    'formatted_address' => $fullAddress.', USA',
                    'geometry' => [
                        'bounds' => [
                            'northeast' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ],
                            'southwest' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ]
                        ],
                        'location' => [
                            'lat' => $this->address['latitude'],
                            'lng' => $this->address['longitude']
                        ],
                        'location_type' => 'ROOFTOP',
                        'viewport' => [
                            'northeast' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ],
                            'southwest' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ]
                        ]
                    ],
                    'place_id' => 'ChIJIQkgpn5YrIkR-ct7zQGLrSU',
                    'types' => [
                        'premise',
                    ]
                ]
            ],
            'status' => 'OK'
        ];
        
        //Mock the Guzzle call that retrieves coordinates
        $this->guzzleResponses['timezone'] = [
                'dstOffset' => 3600,
                'rawOffset' => -18000,
                'status' => 'OK',
                'timeZoneId' => 'America/New_York',
                'timeZoneName' => 'Eastern Daylight Time'
        ];
    }
    
    /**
     * Mock a Geocode response
     */
    protected function mockGeolocation()
    {
        $geolocationVersion = get_package_version('toin0u/geocoder-laravel');
        if (version_compare($geolocationVersion, '4.0', '<')) {
            $this->_mockGeolocation();
            return;
        }
        
        $fullAddress = $this->address['street_number'] . ' '
            . $this->address['street_name'] . ' '
            . (!empty($this->address['unit_type']) ? $this->address['unit_type'] . ' ' : '')
            . (!empty($this->address['unit_number']) ? $this->address['unit_number'] . ', ' : '')
            . $this->address['city'] . ', '
            . $this->address['state']['abbreviation'] . ' '
            . $this->address['zip_code'];
    
        $this->address['latitude'] = !empty($this->address['latitude']) ? $this->address['latitude'] : 35.858733;
        $this->address['longitude'] = !empty($this->address['longitude']) ? $this->address['longitude'] : -78.6537419;
        $coordinates = [
            'latitude' => $this->address['latitude'],
            'longitude' => $this->address['longitude']
        ];
        $coordinates = app(Coordinates::class, $coordinates);
        
        $bounds = [
            'south' => $coordinates->getLatitude(),
            'west' => $coordinates->getLongitude(),
            'north' => $coordinates->getLatitude(),
            'east' => $coordinates->getLongitude()
        ];
        $bounds = app(Bounds::class, $bounds);
        
        $this->address['county'] = !empty($this->address['county']) ? $this->address['county'] : 'Wake County';
        $adminLevels = collect([
            [
                'level' => 1,
                'name' => $this->address['state']['name'],
                'code' => $this->address['state']['abbreviation']
            ],
            [
                'level' => 2,
                'name' => $this->address['county'],
                'code' => $this->address['county']
            ],
            [
                'level' => 3,
                'name' => 'House Creek',
                'code' => 'House Creek'
            ]
        ])->map(function ($adminLevel) {
            return app(AdminLevel::class, $adminLevel);
        })->toArray();
        $adminLevelCollection = [
            'adminLevels' => $adminLevels
        ];
        $adminLevelCollection = app(AdminLevelCollection::class, $adminLevelCollection);
        
        $country = [
            'name' => 'United States',
            'code' => 'US'
        ];
        $country = app(Country::class, $country);
        
        $address = [
            'providedBy' => 'google_maps',
            'adminLevels' => $adminLevelCollection,
            'coordinates' => $coordinates,
            'bounds' => $bounds,
            'streetNumber' => $this->address['street_number'],
            'streetName' => $this->address['street_name'],
            'postalCode' => $this->address['zip_code'],
            'locality' => $this->address['city'],
            'subLocality' => null,
            'country' => $country,
            'timezone' => null
        ];
        $address = app(GoogleAddress::class, $address);
        
        //This is a workaround to some challenges presented when replacing the actual maps API call with our dummy data.
        //ProviderAndDumperAggregator::geocode() has a return type declaration of self that inhibits Mockery chaining.
        //And it's ProviderAggregator object is not instantiated through Laravel bindings, meaning we can't mock that.
        //What we can do is leverage its usage of cache to intercept the provider call with our dummy-response.
        $cacheKey = 'geocoder-'.str_slug(strtolower(urlencode($fullAddress)));
        $cacheDuration = config('geocoder.cache-duration', 60);
        $aggregatorGeocode = function () use ($address) {
            $addresses = [$address];
            $addressCollection = new AddressCollection($addresses);
            return collect($addressCollection);
        };
        app('cache')->remember($cacheKey, $cacheDuration, $aggregatorGeocode);
        
        $this->guzzleResponses['geolocation'] = [
            'results' => [
                [
                    'address_components' => [
                        [
                            'long_name' => (string)$this->address['street_number'],
                            'short_name' => (string)$this->address['street_number'],
                            'types' => [
                                'street_number'
                            ]
                        ],
                        [
                            'long_name' => $this->address['street_name'],
                            'short_name' => $this->address['street_name'],
                            'types' => [
                                'route'
                            ]
                        ],
                        [
                            'long_name' => $this->address['city'],  //North Raleigh
                            'short_name' => $this->address['city'],  //North Raleigh
                            'types' => [
                                'neighborhood',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => $this->address['city'],
                            'short_name' => $this->address['city'],
                            'types' => [
                                'locality',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => 'House Creek',
                            'short_name' => 'House Creek',
                            'types' => [
                                'administrative_area_level_3',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => $this->address['county'],
                            'short_name' => $this->address['county'],
                            'types' => [
                                'administrative_area_level_2',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => $this->address['state']['name'],
                            'short_name' => $this->address['state']['abbreviation'],
                            'types' => [
                                'administrative_area_level_1',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => 'United States',
                            'short_name' => 'US',
                            'types' => [
                                'country',
                                'political'
                            ]
                        ],
                        [
                            'long_name' => (string)$this->address['zip_code'],
                            'short_name' => (string)$this->address['zip_code'],
                            'types' => [
                                'postal_code'
                            ]
                        ]
                    ],
                    'formatted_address' => $fullAddress.', USA',
                    'geometry' => [
                        'bounds' => [
                            'northeast' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ],
                            'southwest' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ]
                        ],
                        'location' => [
                            'lat' => $this->address['latitude'],
                            'lng' => $this->address['longitude']
                        ],
                        'location_type' => 'ROOFTOP',
                        'viewport' => [
                            'northeast' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ],
                            'southwest' => [
                                'lat' => $this->address['latitude'],
                                'lng' => $this->address['longitude']
                            ]
                        ]
                    ],
                    'place_id' => 'ChIJIQkgpn5YrIkR-ct7zQGLrSU',
                    'types' => [
                        'premise',
                    ]
                ]
            ],
            'status' => 'OK'
        ];
        
        //Mock the Guzzle call that retrieves coordinates
        $this->guzzleResponses['timezone'] = [
                'dstOffset' => 3600,
                'rawOffset' => -18000,
                'status' => 'OK',
                'timeZoneId' => 'America/New_York',
                'timeZoneName' => 'Eastern Daylight Time'
        ];
    }
}
