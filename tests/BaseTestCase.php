<?php

namespace Conduit\Tests;

use DB;
use Mail;
use Queue;
use Conduit;
use Storage;
use DOMXPath;
use DOMDocument;
use Notification;
use DatabaseSeeder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionException;
use InvalidArgumentException;
use Illuminate\Routing\Router;
use Conduit\Testing\MockGuzzle;
use Conduit\Middleware\Logging;
use Conduit\Testing\MockStorage;
use App\Http\Middleware\EncryptCookies;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\TestResponse;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Neomerx\CorsIlluminate\Settings\Settings as CorsSettings;

//use Auth;

/**
 * Class BaseTestCase
 * @package Tests
 * @mixin AuthTokenHelper
 */
class BaseTestCase extends TestCase
{
    use Assertions, MockGuzzle, MockStorage, Macroable {
        Macroable::__call as macroCall;
    }
    
    const PHP_UNIT_XML_PATH = 'phpunit.xml';
    
    /**
     * @var \Illuminate\Foundation\Application
     */
    protected static $originalApp;
    
    /**
     * @var string
     */
    protected $baseUrl;
    
    /**
     * @var string
     */
    protected static $migrationPath = 'tests/migrations';
    
    /**
     * @var string
     */
    protected static $seedClass = DatabaseSeeder::class;
    
    /**
     * @var array
     */
    protected $models = [];
    
    /**
     * @var array
     */
    protected $guzzleResponses = [];
    
    /*
     * string
     */
    protected $connectionsToTransact = '';
    
    /**
     * @var string
     */
    protected $token = '';
    
    /**
     * @var bool
     */
    protected $refreshAfterCalls = true;
    
    /**
     * BaseTestCase constructor.
     * @param string|null $name
     * @param array $data
     * @param string $dataName
     * @throws ReflectionException
     */
    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        
        $tokenHelper = app(AuthTokenHelper::class);
        static::mixin($tokenHelper);
    }
    
    /**
     * Mix another object into the class.
     *
     * @param  object  $mixin
     * @return void
     * @throws ReflectionException
     */
    public static function mixin($mixin)
    {
        $mixinReflection = new ReflectionClass($mixin);
        $methods = $mixinReflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        /** @var ReflectionMethod $method */
        foreach ($methods as $method) {
            $macro = function (...$args) use ($mixin, $method) {
                return $method->invoke($mixin, ...$args);
            };
            static::macro($method->name, $macro);
        }
    }
    
    /**
     * Initial setup for test
     */
    public function setup()
    {
        parent::setUp();
        
        $this->refreshApplication();
        
        $this->firedEvents;
        if (!$this->safetyNet()) {
            dd('Tests haulted!  Go back and set up a phpunit.xml file with local DB configs, and make sure your tests are configured to use it, before you break something!');
        }
        
        $this->runDatabaseMigrations();
        #Mail::fake();
        Mail::swap(app(MailFake::class));
        Notification::fake();
        Queue::fake();
        $this->setupMockHandler();
        $this->setupMockStorage();
        $this->baseUrl = config('app.url');
    }
    
    /**
     * @return bool
     * @throws FileNotFoundException
     */
    protected function safetyNet()
    {
        $client = Storage::createLocalDriver(['root' => app_path('..')]);
        if (!$client->exists(self::PHP_UNIT_XML_PATH)) {
            return false;
        }
        $xml = $client->get(self::PHP_UNIT_XML_PATH);
        
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        
        if (!$host = $xpath->query("//env[@name='DB_HOST']")->item(0)) {
            return false;
        }
        $host = $host->getAttribute('value');
        
        if (!$database = $xpath->query("//env[@name='DB_DATABASE']")->item(0)) {
            return false;
        }
        $database = $database->getAttribute('value');
        
        if ($host != env('DB_HOST') || $database != env('DB_DATABASE')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Configure target database for transactions
     */
    protected function setUpTraits()
    {
        $this->connectionsToTransact = [
            config('database.default')
        ];
        
        parent::setUpTraits();
    }
    
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = $this->requireBootstrap();
        /** @var \App\Console\Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        
        return $app;
    }
    
    /**
     * @return \Illuminate\Foundation\Application
     */
    protected function requireBootstrap()
    {
        if (preg_match('/\/(packages)|(vendor)\/conduit\/tests$/', __DIR__)) {
            $app = require __DIR__.'/../../../bootstrap/app.php';
        } else {
            $app = require __DIR__.'/../bootstrap/app.php';
        }
        
        return $app;
    }
    
    /**
     * Define hooks to migrate the database before and after each test.
     * Not using the DatabaseMigrations trait here because we need to specify the directory
     *
     * @param bool $rollback
     * @return void
     */
    public function runDatabaseMigrations($rollback = false)
    {
        $this->artisan('migrate', ['--path' => self::$migrationPath]);
        $this->artisan('db:seed', ['--class' => self::$seedClass]);
        $this->app[Kernel::class]->setArtisan(null);
        if ($rollback) {
            $this->beforeApplicationDestroyed(function () {
                $this->artisan('migrate:rollback', ['--path' => self::$migrationPath]);
            });
        }
    }
    
    /**
     * @param string $reference
     * @param array $parameters
     * @param array $body
     * @param array $headers
     * @param array $files
     * @param array $cookies
     * @return array
     */
    public function callRoute(
        $reference,
        array $parameters = [],
        array $body = [],
        array $headers = [],
        array $files = [],
        array $cookies = []
    ) {
        $headers = $this->setDefaultHeaders($headers);
        //TODO: Replace most of this with Conduit\parse_http_request()
        $route = app(Router::class)->getRoutes()->getByName($reference);
        foreach ($cookies as $name => $cookie) {
            $this->app->resolving(EncryptCookies::class, function (EncryptCookies $cookie) use ($name) {
                $cookie->disableFor($name);
            });
        }
        
        if (!$route) {
            throw new InvalidArgumentException("No route by the name '$reference' was found.");
        }
        
        $method = array_first($route->methods());
        $server = $this->transformHeadersToServerVars($headers);
        $uri = Conduit\resolve_uri($route->uri(), $parameters);
        $response = $this->call($method, $uri, $body, $cookies, $files, $server);
        $response = Conduit\parse_http_response($response);
        $response['route'] = $route->getAction();
        
        if (is_array($response['content']) || (is_object($response['content']) && $response['content'] instanceof \StdClass)) {
            $response['content'] = json_decode(json_encode($response['content']), true);
        }
        
        $headers = Conduit\parse_http_headers($headers);
        
        $response['request'] = compact('method', 'uri', 'body', 'cookies', 'files', 'headers');
        
        return $response;
    }
    
    /**
     * TODO: Use this override to somehow reset the singleton definitions.  refreshApplication() and app->rebinding() don't appear to be the solution, at least on their own.
     * @param string $method
     * @param string $uri
     * @param array $parameters
     * @param array $cookies
     * @param array $files
     * @param array $server
     * @param null $content
     * @return TestResponse
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        
        return $response;
    }
    
    /**
     * Keep CORS happy, and not get in the way of an assertion test
     *
     * @param array $headers
     * @return array
     */
    public function setDefaultHeaders(array $headers = [])
    {
        $headers = array_change_key_case($headers);
        $origin = $this->app['config']['cors-illuminate'][CorsSettings::KEY_SERVER_ORIGIN];
        $defaults = ['origin' => $origin['scheme'].'://'.$origin['host'].':'.$origin['port']];
        $headers = array_merge($defaults, $headers);
        
        return $headers;
    }
    
    /**
     * @param array $includes
     * @return string
     */
    protected function fractalIncludes(array $includes)
    {
        return collect(array_dot($includes))->mapWithKeys(function ($include, $field) {
            $parts = explode('.', $field);
            if (is_numeric(last($parts))) {
                array_splice($parts, -1, 1, $include);
                $field = implode('.', $parts);
                $include = null;
            }
            return [$field => $include];
        })->keys()->implode(',');
    }
    
    /**
     * @param array $expected
     * @param array|\StdClass $actual
     * @param array $parent
     * @return bool|string
     */
    protected function array_comparison_multidimensional(array $expected, $actual, array $parent = [])
    {
        $actual = json_decode(json_encode($actual), true);
        
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual)) {
                $parent[] = $field;
                $parent = implode('.', $parent);
                return "Field '$parent' does not exist in the target array'";
            }
            
            if (is_array($value)) {
                $parent[] = $field;
                if (!is_array($actual[$field])) {
                    $parent = implode('.', $parent);
                    return "'$parent' is expected to be an array.  ".gettype($actual[$field]).' found.';
                }
                
                $method = last(explode('::', __METHOD__));
                $nestedComparison = $this->$method($value, $actual[$field], $parent);
                if ($nestedComparison !== true) {
                    return $nestedComparison;
                }
                array_pop($parent);
            } elseif ($value !== $actual[$field]) {
                $parent[] = $field;
                $parent = implode('.', $parent);
                return "'$parent' expected to be '$value'.  '".print_r($actual[$field], true)."' found.";
            }
        }
        
        return true;
    }
    
    /**
     * Clear any 'forever' cache items set by ResponseFactory
     */
    protected function tearDown()
    {
        if (app('cache')->has('test-response')) {
            app('cache')->forget('test-response');
        }
        
        $this->beforeApplicationDestroyed(function () {
            DB::disconnect();
        });
        
        Logging::flushTransactionLog();
        Logging::disableTransactionLog();
        
        parent::tearDown();
    }
    
    /**
     * @param string|object $class
     * @param string $property
     * @return mixed
     */
    protected function getProperty($class, $property)
    {
        $reflection = app(ReflectionClass::class, ['argument' => $class]);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property = $property->getValue($class);
        
        return $property;
    }
    
    /**
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call($method, array $arguments)
    {
        //
        
        return $this->macroCall($method, $arguments);
    }
}
