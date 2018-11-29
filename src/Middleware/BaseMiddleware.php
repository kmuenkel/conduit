<?php

namespace Conduit\Middleware;

/**
 * TODO: Turn this into a macro-able class, possibly a final one, with all the pre-written middleware in underscore-prefixed protected methods instead of their own classes.  Alias the Macroable __call method, and set this up with its own, that uses debug_backtrace to avoid infinite recursion in the event that a middleware wants to preempt the desired action with its own use of an adapter class.  All middleware closures will then need to be aliased, not merely hinted at with particular config keys.
 * 
 * Class BaseMiddleware
 * @package Conduit\Middleware
 */
abstract class BaseMiddleware implements AdapterMiddleware
{
    /**
     * @var array
     */
    protected $config = [];
    
    /**
     * Oauth constructor.
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
    
    /**
     * @return string
     */
    public function guessCacheKey()
    {
        $caller = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->filter(function ($trace) {
            return (isset($trace['class']) && class_exists($trace['class']) && in_array(AdapterMiddleware::class, class_implements($trace['class'])) && $trace['class'] != __CLASS__);
        })->pluck('class')->first();
        
        return $caller ?: get_class($this);
    }
}