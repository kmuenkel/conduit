<?php

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
                'allow_redirects' => RedirectMiddleware::$defaultSettings,
                'http_errors' => true,
                'decode_content' => true,
                'verify' => env('VERIFY_CA', false),
                'cookies' => true,
                'proxy' => [
                    'http' => env('HTTP_PROXY'),
                    'https' => env('HTTPS_PROXY'),
                    'no' => explode(',', str_replace(' ', '', env('NO_PROXY', '')))
                ],
                'headers' => [
                    'user-agent' => default_user_agent()
                ],
                'forward_headers' => true
            ]
        ]
    ]
];
