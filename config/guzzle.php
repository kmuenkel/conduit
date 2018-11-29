<?php

use GuzzleHttp\HandlerStack;
use GuzzleHttp\RedirectMiddleware;
use function GuzzleHttp\default_user_agent;

/*
|--------------------------------------------------------------------------
| Default Guzzle Configurations
|--------------------------------------------------------------------------
|
| Values here are used in GuzzleHttp\Client and Conduit
|
*/
return [
    'bridge_debug' => env('BRIDGE_DEBUG', false),
    #'handler' => HandlerStack::create(),   //https://github.com/laravel/framework/issues/9625#issuecomment-121339093
    'base_uri' => env('API_BASE_URL'),
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
];
