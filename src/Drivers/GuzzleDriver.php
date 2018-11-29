<?php

namespace Conduit\Drivers;

use Conduit;
use Conduit\Middleware;
use Http\Client\HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\RequestInterface;
use App\Http\Middleware\EncryptCookies;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Cookie\CookieJarInterface;
use Psr\Http\Message\UploadedFileInterface;
use Conduit\Exceptions\BridgeTransactionException;
use Illuminate\Contracts\Encryption\DecryptException;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;

class GuzzleDriver implements Driver
{
    /**
     * @var HttpClient
     */
    protected $httpClient;
    
    /**
     * @var GuzzleClientInterface
     */
    protected $client;
    
    /**
     * @var CookieJarInterface
     */
    protected $cookies;
    
    /**
     * @var bool
     */
    protected $encodeQuery = true;
    
    /**
     * @var string
     */
    public static $defaultConfig = 'guzzle';
    
    /**
     * @var int
     */
    public static $counter = 0;
    
    /**
     * @var array
     */
    protected $serviceConfig = [];
    
    /**
     * GuzzleDriver constructor.
     * @param array $serviceConfig
     * @param CookieJarInterface|null $cookies
     */
    public function __construct(
        array $serviceConfig = [],
        CookieJarInterface $cookies = null
    ) {
        self::$counter++;
        $this->serviceConfig = $serviceConfig;
        $this->client = $this->makeClient($serviceConfig);
        //TODO: Need to add override classes for the concrete classes that currently implement HttpClient.  They need to normalize the controller parameters, and implement a new interface that extends HttpClient and indicates the controller parameters.  Otherwise the abstraction here is moot.  Only the Guzzle HttpClient can actually receive a 'client' parameter. 
        $this->httpClient = app(HttpClient::class, ['client' => $this->client]);
        //TODO: After the above has been addressed, usage of CookieJarInterface and GuzzleClientInterface will be the only things binding this class directly to Guzzle.  They should then be abstracted out into a ../Clients/GuzzleClientFactory class so this class can be turned into a more generic driver, referring to the factory for an HttpClient instance only if one is not injected here. 
        $this->cookies = $cookies ?: app(CookieJarInterface::class, ['strictMode' => true]);
    }
    
    /**
     * @param bool $encode
     * @return $this
     */
    public function toggleQueryEncoding($encode = true) : GuzzleDriver
    {
        $this->encodeQuery = $encode;
        return $this;
    }
    
    /**
     * @return HttpClient
     */
    public function getClient() : HttpClient
    {
        return $this->httpClient;
    }
    
    /**
     * @return GuzzleClientInterface
     */
    public function getGuzzleClient() : GuzzleClientInterface
    {
        return $this->client;
    }
    
    /**
     * @param array $serviceConfig
     * @return array
     */
    protected function normalizeConfig(array $serviceConfig = []) : array
    {
        $globalConfig = (array)config(self::$defaultConfig, []);
        $globalMiddleware = array_get($globalConfig, 'middleware', []);
        $globalConfig = array_get($globalConfig, 'config', $globalConfig);
        
        $defaultConfig = (array)config('services.'.get_class($this), []);
        $defaultMiddleware = array_get($defaultConfig, 'middleware', []);
        $defaultConfig = array_get($defaultConfig, 'config', $defaultConfig);
        
        $middleware = (array)array_get($serviceConfig, 'middleware', []);
        $config = array_get($serviceConfig, 'config', $serviceConfig);
        $config = array_merge($globalConfig, $defaultConfig, $config);
        
        $middleware = array_merge($globalMiddleware, $defaultMiddleware, $middleware);
        $middleware = collect($middleware)->mapWithKeys(function ($middlewareConfig, $className) {
            if (is_string($middlewareConfig) && is_int($className)) {
                $className = $middlewareConfig;
                $middlewareConfig = [];
            }
            $middlewareConfig = (array)$middlewareConfig;
            
            return [$className => $middlewareConfig];
        })->toArray();
        
        return compact('config', 'middleware');
    }
    
    /**
     * @param array $config
     * @return array
     */
    protected function guessMiddleware(array $config = []) : array
    {
        $defaultMiddleware = [];
        
        if (array_key_exists('oauth', $config) && $config['oauth'] !== false && !is_null($config['oauth'])) {
            $defaultMiddleware[Middleware\Oauth::class] = (array)$config['oauth'];
            $defaultMiddleware[Middleware\Retry::class] = array_get($config, 'retry', []);
        }
        
        if (array_key_exists('saml', $config) && $config['saml'] !== false && !is_null($config['saml'])) {
            $defaultMiddleware[Middleware\Saml::class] = (array)$config['saml'];
        }
        
        if (array_key_exists('idp', $config) && $config['idp'] !== false && !is_null($config['idp'])) {
            $defaultMiddleware[Middleware\Idp::class] = (array)$config['idp'];
            $defaultMiddleware[Middleware\Retry::class] = array_get($config, 'retry', []);
        }
        
        if (array_key_exists('jwt', $config) && $config['jwt'] !== false && !is_null($config['jwt'])) {
            $defaultMiddleware[Middleware\Jwt::class] = (array)$config['jwt'];
            $defaultMiddleware[Middleware\Retry::class] = array_get($config, 'retry', []);
        }
        
        if (array_key_exists('basic', $config) && $config['basic'] !== false && !is_null($config['basic'])) {
            $defaultMiddleware[Middleware\Basic::class] = (array)$config['basic'];
        }
        
        if (array_key_exists('session', $config) && $config['session'] !== false && !is_null($config['session'])) {
            $defaultMiddleware[Middleware\Session::class] = (array)$config['session'];
            $defaultMiddleware[Middleware\Retry::class] = array_get($config, 'retry', []);
        }
        
        if ((array_key_exists('logging', $config) && $config['logging'] !== false && !is_null($config['logging']))
            || (array_key_exists('bridge_debug', $config) && $config['bridge_debug'])
        ) {
            $defaultMiddleware[Middleware\Logging::class] = (array)array_get($config, 'logging', []);
        }
        
        return $defaultMiddleware;
    }
    
    /**
     * @param array $serviceConfig
     * @return GuzzleClientInterface
     */
    public function makeClient(array $serviceConfig = []) : GuzzleClientInterface
    {
        $serviceConfig = $this->normalizeConfig($serviceConfig);
        $config = $serviceConfig['config'];
        
        $defaultMiddleware = array_merge(
            $this->guessMiddleware($serviceConfig['config']),
            $this->guessMiddleware($serviceConfig['middleware'])
        );
        $middleware = array_merge($defaultMiddleware, $serviceConfig['middleware']);
        
        //Make certain the logging middleware is the last one to be applied, so it can reflect any potential changes to the request applied by the others 
        if (array_key_exists(Middleware\Logging::class, $middleware)) {
            $logging = $middleware[Middleware\Logging::class];
            unset($middleware[Middleware\Logging::class]);
            $middleware[Middleware\Logging::class] = $logging;
        }
        
        foreach ($middleware as $class => $middlewareConfig) {
            $interfaces = class_exists($class) ? class_implements($class) : [];
            if (!in_array(Middleware\AdapterMiddleware::class, $interfaces)) {
                //TODO: Work out a way to enforce this.  Need to replace the Middleware aliases, not just add new entries for that Middleware
                //throw new InvalidArgumentException("Http Middleweare '$class' must be an instance of ".AdapterMiddleware::class.'.');
                unset($middleware[$class]);
            }
        }
        
        $config['cookies'] = $this->cookies ?: array_get($serviceConfig['config'], 'cookies');
        $config['handler'] = $config['handler'] ?? HandlerStack::create();
        
        collect($middleware)->each(function ($middlewareConfig, $className) use (&$config) {
            $middleware = app($className, [
                'config' => $middlewareConfig
            ])->getMiddleware();
            $config['handler']->remove($className);
            $config['handler']->push($middleware, $className);
        });
        
        //Prevent infinite binding recursion
        if (self::$counter > 1) {
            $stackTrace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->map(function (array $trace) {
                $trace['class'] = $trace['class'] ?? 'Closure';
                return $trace['class'].'::'.$trace['function'];
            })->toArray();
            if (array_count_values($stackTrace)[__METHOD__] > 1) {
                return new \GuzzleHttp\Client($config);
            }
        }
        
        return app(GuzzleClientInterface::class, compact('config'));
    }
    
    /**
     * @param $uri
     * @param string $method
     * @param array $parameters
     * @param array $headers
     * @param array $cookies
     * @return array
     * @throws BridgeTransactionException
     * @throws GuzzleException
     */
    public function send($uri, $method = 'get', $parameters = [], array $headers = [], array $cookies = []) : array
    {
        $method = strtolower($method);
        $parameters = $this->generateRequest($uri, $method, $parameters, $headers, $cookies);
        $request = app(RequestInterface::class, $parameters['request']);
        
        try {
            $response = $this->client->send($request, $parameters['options']);
        } catch (ClientException $error) {
            throw new BridgeTransactionException("Call to ($method)$uri failed.", 0, $error);
        } catch (ServerException $error) {
            throw new BridgeTransactionException("Call to ($method)$uri failed.", 1, $error);
        }
        
        return Conduit\parse_http_response($response, $this->cookies);
    }
    
    /**
     * @param array $cookies
     * @param string $uri
     * @return CookieJarInterface
     */
    public function fillCookieJar(array $cookies = [], $uri) : CookieJarInterface
    {
        $cookieFields = array_keys(app(SetCookie::class)->toArray());
        $cookieFieldMap = array_change_key_case(array_combine($cookieFields, $cookieFields));
        
        $encryptCookies = app(EncryptCookies::class);
        
        foreach ($cookies as $name => $cookie) {
            $data = ['Name' => $name];
            
            $cookie = (array)$cookie;
            foreach ($cookie as $field => $value) {
                $field = is_int($field) ? 'Value' : $field;
                $fieldName = $cookieFieldMap[strtolower($field)];
                $data[$fieldName] = $value;
            }
            
            try {
                $decrypted = decrypt($data['Value']);
                $encrypted = $data['Value'];
            } catch (DecryptException $error) {
                $decrypted = $data['Value'];
                $encrypted = encrypt($data['Value']);
            }
            
            $data['Value'] = $encryptCookies->isDisabled($data['Name']) ? $decrypted : $encrypted;
            
            if (!array_key_exists('Domain', $data)) {
                $domain = array_get(Conduit\parse_uri($uri), 'host');
                $domain = $domain ?: array_get($this->serviceConfig, 'base_uri');
                $data['Domain'] = $domain;
            }
            
            $data['Domain'] = (array)$data['Domain'];
            foreach ($data['Domain'] as $index => $domain) {
                $data['Domain'][$index] = array_get(Conduit\parse_uri($domain), 'host');
            }
            $data['Domain'] = (count($data['Domain']) == 1) ? current($data['Domain']) : $data['Domain'];
            
            //TODO: Be careful with this, it's not sustainable.  See https://stackoverflow.com/a/3290474
            if (!array_key_exists('Expires', $data)) {
                $data['Expires'] = strtotime('+ 10 YEARS');
            }
            
            $cookie = app(SetCookie::class, compact('data'));
            $this->cookies->setCookie($cookie);
        }
        
        return $this->cookies;
    }
    
    /**
     * @param $uri
     * @param string $method
     * @param array $parameters
     * @param array $headers
     * @param array $cookies
     * @return array
     */
    public function generateRequest($uri, $method = 'get', $parameters = [], array $headers = [], array $cookies = []) : array
    {
        $query = $path = [];
        $body = $parameters;
        $parameters = [];
        if (count(array_intersect(['query', 'body', 'path'], array_keys($body)))) {
            $parameters = array_merge(['query' => [], 'body' => []], $body);
            extract($parameters);
            $parameters = $path;
        }
        
        if ($method == 'get') {
            $query = array_merge($body, $query);
            $body = [];
        }
        
        if (!$this->encodeQuery) {
            $parameters = array_merge($parameters, $query);
            $query = [];
        }
        
        $uri = Conduit\resolve_uri($uri, $parameters, '', $this->encodeQuery);
        
        $headers = array_change_key_case($headers);
        $dataType = array_get($headers, 'content-type', Conduit\guess_content_type($body));
        $dataType = current(explode(';', $dataType));
        #unset($headers['content-type']);
        $body = Conduit\prime_http_content($body, $dataType);
        
        $request = [
            'method' => $method,
            'uri' => $uri,
            'headers' => $headers
        ];
        
        $options = [];
        
        $cookies = !empty($cookies) ? $this->fillCookieJar($cookies, $uri) : $this->cookies;
        if (!empty($cookies->toArray())) {
            $options['cookies'] = $cookies;
        }
        
        if ($dataType == 'application/json') {
            $options['json'] = $body;
        } elseif ($dataType == 'application/x-www-form-urlencoded') {
            $options['form_params'] = $body;
            
            $files = [];
            foreach ($body as $name => $field) {
                if (is_resource($field) && in_array(get_resource_type($field), ['file', 'stream'])) {
                    $files[$name] = $field;
                    unset($body[$name]);
                } elseif (is_object($field) && $field instanceof UploadedFileInterface) {
                    $files[$name] = $field->getStream()->getContents();
                    unset($body[$name]);
                }
            }
            
            if (!empty($files)) {
                $options['multipart'] = [];
                $fields = explode('&', http_build_query($body));
                foreach ($fields as $field) {
                    list($name, $field) = explode('=', $field);
                    $options['multipart'][] = [
                        'name' => urldecode($name),
                        'contents' => urldecode($field)
                    ];
                }
                
                foreach ($files as $name => $contents) {
                    $options['multipart'][] = [
                        'name' => $name,
                        'contents' => $contents
                    ];
                }
            }
        } elseif ($dataType == 'application/xml') {
            $request['body'] = $body;
        }
        
        if (!empty($query)) {
            $options['query'] = $query;
        }
        
        return compact('request', 'options');
    }
}
