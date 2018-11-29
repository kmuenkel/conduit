<?php

namespace Conduit\Testing;

use Illuminate\Http\Request;

/*
----------------------------------------------
Place the following among your Routes.  These are needed because raw CURL calls inside restricted methods cannot be mocked for the purposes of tests

if (config('app.debug')) {
    Route::post('curltest/{endpoint?}', function (Illuminate\Http\Request $request) {
        return app(\Conduit\Testing\ResponseFactory::class)->getResponse($request);
    });
    Route::get('curltest/{endpoint?}', function (Illuminate\Http\Request $request) {
        return app(\Conduit\Testing\ResponseFactory::class)->getResponse($request);
    });
    Route::put('curltest/{endpoint?}', function (Illuminate\Http\Request $request) {
        return app(\Conduit\Testing\ResponseFactory::class)->getResponse($request);
    });
    Route::patch('curltest/{endpoint?}', function (Illuminate\Http\Request $request) {
        return app(\Conduit\Testing\ResponseFactory::class)->getResponse($request);
    });
    Route::delete('curltest/{endpoint?}', function (Illuminate\Http\Request $request) {
        return app(\Conduit\Testing\ResponseFactory::class)->getResponse($request);
    });
}

 */

class ResponseFactory
{
    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function getResponse(Request $request)
    {
        $responses = app('cache')->get('test-response', null);
        
        $request = [
            'method' => $request->method(),
            'url' => parse_url($request->fullUrl()),
            'parameters' => $request->route()->parameters(),
            'input' => $request->all(),
            'files' => $request->files
        ];
        
        $response = collect($responses)->filter(function (array $response) use ($request) {
            $passes = true;
            $response = array_dot($response);
            $request = array_dot($request);
            foreach ($response as $part => $rule) {
                if ($part == 'response') {
                    continue;
                }
                $passes &= is_callable($rule) ? $rule($request[$part]) : ($request[$part] == $rule);
            }
            return $passes;
        })->pluck('response')->first() ?: $request;
        
        if (is_callable($response)) {
            $response = $response($request);
        }
        
        return response($response);
    }
    
    /**
     * This uses cache so it can persist between the instance of this app from which the test is being
     * executed, to the instance being accessed by a CURL call
     *
     * @param array $responses
     */
    public function setResponse(array $responses)
    {
       cache(['test-response' => $responses], 300);
    }
}
