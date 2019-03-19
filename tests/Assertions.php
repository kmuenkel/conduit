<?php

namespace Conduit\Tests;

use Conduit;
use Carbon\Carbon;
use Illuminate\Routing\Router;

trait Assertions
{
    /**
     * @param string $expectedRoute
     * @param string $routeName
     * @param array $parameters
     */
    protected function assertRoute($expectedRoute, $routeName, $parameters = [])
    {
        $expectedRoute = trim($expectedRoute, '/');
        $uri = app(Router::class)->getRoutes()->getByName($routeName)->uri();
        $uri = Conduit\parse_uri(Conduit\resolve_uri($uri, $parameters));
        $actualRoute = trim($uri['path'], '/');
        
        self::assertEquals($expectedRoute, $actualRoute);
    }
    
    /**
     * Check to see if the array contents are as expected, even if the order is not
     * @param $expected
     * @param $actual
     * @param bool $strict
     */
    public static function assertArrayContents($expected, $actual, $strict = false)
    {
        $expectedAssociative = !is_numeric_array($expected);
        
        $match = true;
        foreach ($expected as $element) {
            if (array_search($element, $actual) === false) {
                $match = false;
                break;
            }
        }
        
        if ($strict || $expectedAssociative) {
            if ($expectedAssociative) {
                $match &= array_keys_exist(array_keys($expected), $actual, $strict);
            }
        }
        
        $expected = 'Expected: '.print_r($expected, true);
        $actual = 'Actual: '.print_r($actual, true);
        
        self::assertTrue((bool)$match, 'Array content does not match: '."\n$expected\n$actual");
    }
    
    /**
     * @param string $expected
     * @param string $actual
     * @param int $toleranceBefore
     * @param int $toleranceAfter
     * @param string $format
     */
    public static function assertSimilarTimes($expected, $actual, $toleranceBefore = 1, $toleranceAfter = 1, $format = 'Y-m-d H:i:s')
    {
        $actual = Carbon::createFromFormat($format, $actual);
        $expected = Carbon::createFromFormat($format, $expected);
        $expectedStart = (clone $expected)->subSeconds($toleranceBefore);
        $expectedEnd = $expected->addSeconds($toleranceAfter);
        
        $isInRange = $actual->between($expectedStart, $expectedEnd);
        
        self::assertTrue($isInRange);
    }
}
