<?php

use GuzzleHttp\RequestOptions;
use Conduit\Bridges\GuzzleBridge;
use GuzzleHttp\RedirectMiddleware;
use function GuzzleHttp\default_user_agent;

return [
    'default_service' => env('DEFAULT_SERVICE', 'leo'),
    'default_bridge' => 'guzzle',

    'services' => [
        //
    ],

    'bridges' => [
        'guzzle' => [
            'bridge' => GuzzleBridge::class,
            'config' => [
//                'handler' => \GuzzleHttp\HandlerStack::create(),   //https://github.com/laravel/framework/issues/9625#issuecomment-121339093
                RequestOptions::ALLOW_REDIRECTS => RedirectMiddleware::$defaultSettings,
                RequestOptions::HTTP_ERRORS => true,
                RequestOptions::DECODE_CONTENT => true,
                RequestOptions::VERIFY => env('VERIFY_CA', false),
                RequestOptions::COOKIES => true,
                'strict_mode' => true,
                RequestOptions::PROXY => [
                    'http' => env('HTTP_PROXY'),
                    'https' => env('HTTPS_PROXY'),
                    'no' => explode(',', str_replace(' ', '', env('NO_PROXY', '')))
                ],
                RequestOptions::HEADERS => [
                    'user-agent' => default_user_agent()
                ]
            ]
        ]
    ]
];
