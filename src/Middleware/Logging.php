<?php

namespace Conduit\Middleware;

use Monolog\Logger;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\File;
use Monolog\Handler\ErrorLogHandler;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Promise\PromiseInterface;

class Logging extends BaseMiddleware
{
    public static $defaultLogLocation = 'logs/laravel.log';
    
    protected static $loggingEnabled = false;
    
    protected static $transactionLog = [];
    
    /**
     * @return callable
     */
    public function getMiddleware() : callable
    {
        $errorLogPath = storage_path(array_get($this->config, 'logLocation', self::$defaultLogLocation));
        if (!File::exists($errorLogPath)) {
            File::makeDirectory(File::dirname($errorLogPath), $mode = 0777, true, true);
        }
        
        $logHandle = fopen($errorLogPath, 'a');
        $logger = app(StreamHandler::class, ['stream' => $logHandle]);
        
        $defaults = [
            'format' => "METHOD:{method}; URI:{uri}; HEADERS:{req_headers}; BODY:{req_body} ----- CODE:{code}; HEADERS:{res_headers}; BODY:{res_body}",
            'logger' => Logger::class,  //Psr\Log\LoggerInterface
            'handler' => $logger,  //Monolog\Handler\HandlerInterface
            #'handler' => app(ErrorLogHandler::class),  //Use this to send to the php error_log instead of Laravel's
        ];
        
        $config = array_merge($defaults, $this->config);
        
        $middleware = Middleware::log(
            app($config['logger'], ['name' => 'Guzzle', 'handlers' => [$config['handler']]]),
            app(MessageFormatter::class, ['template' => $config['format']])
        );
        
        $middleware = function (callable $handler) use ($middleware) {
            return $middleware(function (RequestInterface $request, array $options) use ($handler) {
                /** @var PromiseInterface $response */
                $response = $handler($request, $options);
                
                if (self::$loggingEnabled) {
                    self::$transactionLog[] = [
                        'request' => $request,
                        'response' => $response->wait()
                    ];
                }
                
                return $response;
            });
        };
        
        return $middleware;
    }
    
    public static function enableTransactionLog()
    {
        self::$loggingEnabled = true;
    }
    
    public static function disableTransactionLog()
    {
        self::$loggingEnabled = false;
    }
    
    public static function getTransactionLog()
    {
        return self::$transactionLog ;
    }
    
    public static function flushTransactionLog()
    {
        self::$transactionLog = [];
    }
}
