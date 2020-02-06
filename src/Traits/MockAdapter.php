<?php

namespace Conduit\Traits;

use GuzzleHttp\Client;
use Conduit\Bridges\Bridge;
use GuzzleHttp\HandlerStack;
use Conduit\Bridges\GuzzleBridge;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * Trait MockAdapter
 * @package Conduit\Traits
 * @property Container $app
 */
trait MockAdapter
{
    /**
     * @return Bridge
     */
    public function setUpFakeAdapter()
    {
        $handler = app(MockHandler::class);
        $bridge = app(GuzzleBridge::class)->setHandler($handler);

        if ($this->app) {
            $this->app->bind(Client::class, function (Application $app, array $args = []) use ($handler) {
                $args = compile_arguments(Client::class, $args);
                $handler = HandlerStack::create($handler);
                $args['config']['handler'] = $handler;

                return new Client($args['config']);
            });

            $this->app->bind(GuzzleBridge::class, function (Application $app, array $args) use ($bridge) {
                return $bridge;
            });
        }

        return $bridge;
    }
}
