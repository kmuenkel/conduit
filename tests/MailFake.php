<?php

namespace Conduit\Tests;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Testing\Fakes\MailFake as BaseMailFake;

/**
 * TODO: See if there's a way to use mixins to abstract this behavior, and allow a-la-carte inclusion of base methods into any kind of Fake object
 * Class MailFake
 * @package Tests
 */
class MailFake extends BaseMailFake
{
    use Macroable;
    
    /**
     * @var array
     */
    public static $permittedMethods = [
        'render'
    ];
    
    /**
     * MailFake constructor.
     * @param Mailer $mixin
     */
    public function __construct(Mailer $mixin)
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );
        
        foreach ($methods as $method) {
            if (in_array($method->name, static::$permittedMethods)) {
                $method->setAccessible(true);
                $macro = function (...$args) use ($mixin, $method) {
                    return $method->invoke($mixin, ...$args);
                };
                static::macro($method->name, $macro);
            }
        }
    }
}
