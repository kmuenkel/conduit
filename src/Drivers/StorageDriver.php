<?php

namespace Conduit\Drivers;

ini_set('memory_limit', '1024M');

use Conduit;
use Conduit\Exceptions\BridgeTransactionException;
use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Storage;

/**
 * TODO: Separate the XML generation from the Storage push so the functionality can be extended to accommodate SOAP
 *
 * Class StorageDriver
 * @package Conduit\Drivers
 */
class StorageDriver implements Driver
{
    /**
     * @var Storage
     */
    protected $client;
    
    /**
     * @var array
     */
    protected $serviceConfig = [];
    
    /**
     * @var string
     */
    protected $conflictResolution = 'rename';
    
    /**
     * StorageDriver constructor.
     * @param array $serviceConfig
     */
    public function __construct(array $serviceConfig = [])
    {
        $this->serviceConfig = $serviceConfig;
        $this->client = $this->makeClient($serviceConfig);
    }
    
    /**
     * @param string $resolveBy
     * @return $this
     */
    public function conflictResolutionMethod($resolveBy = 'rename') : self
    {
        $permittedResolutions = ['rename', 'overwrite', 'ignore'];
        if (!in_array($resolveBy, $permittedResolutions)) {
            throw new InvalidArgumentException('Argument 1 of '.__METHOD__.' must be one of the following values: '.implode(', ', $permittedResolutions));
        }
        $this->conflictResolution = $resolveBy;
        
        return $this;
    }
    
    /**
     * @return Storage
     */
    public function getClient()
    {
        return $this->client;
    }
    
    /**
     * @param array $serviceConfig
     * @return FilesystemAdapter
     */
    public function makeClient(array $serviceConfig = [])
    {
        $defaultDisk = config('filesystems.default');
        $this->serviceConfig = array_merge(['disk' => $defaultDisk], $serviceConfig);
        $client = array_get($this->serviceConfig, 'driver', Storage::disk($this->serviceConfig['disk']));
        return $client;
    }
    
    /**
     * @param FilesystemAdapter|null $adapter
     * @return FilesystemAdapter
     */
    public function setClient(FilesystemAdapter $adapter = null)
    {
        return $this->client = $adapter ?: Storage::createLocalDriver(['root' => storage_path('test/xml')]);
    }
    
    /**
     * @param $uri
     * @param string $method
     * @param array $parameters
     * @return array
     * @throws BridgeTransactionException
     */
    public function send($uri, $method = 'put', $parameters = []) : array
    {
        $content = Conduit\array_to_xml($parameters);
        
        $incrementedFilePath = $uri;
        
        //To avoid overwriting previous files if the same pay-group is re-run, keep incrementing the filename until a unique version is found
        $increment = 1;
        if ($this->conflictResolution != 'overwrite') {
            while ($this->client->exists("$incrementedFilePath.xml")
                || $this->client->exists("$incrementedFilePath.ready")) {
                $increment++;
                $incrementedFilePath = $uri.'_'.$increment;
            }
        }
        
        $root = $this->client->getAdapter()->getPathPrefix() ?: $this->client->getAdapter()->getHost();
        
        if ($increment > 1 && $this->conflictResolution == 'ignore') {
            $increment--;
            $incrementedFilePath = ($increment > 1) ? $uri.'_'.$increment : $uri;
            
            //TODO: See if this can be turned into a middleware later
            if (env('BRIDGE_DEBUG')) {
                //TODO: Support all file drivers, not just these two
                $fileHandle = fopen(storage_path('/logs/laravel.log'), 'a');
                $logHandle = app(StreamHandler::class, ['stream' => $fileHandle]);
                $logger = app(Logger::class, ['name' => 'StorageDriver', 'handlers' => [$logHandle]]);
                $logger->debug("File $root/$incrementedFilePath is already present");
            }
            $success = true;
        } else {
            //Fail loudly if the file upload fails.  More importantly, kill this script before it has the opportunity to make the filenames as '.ready'
            try {
                //TODO: Support file-retrieval rather than just 'put'
                $success = $this->client->$method("$incrementedFilePath.xml", $content->saveXML());
            } catch (Exception $error) {
                $original = get_class($error).': '.$error->getMessage();
                throw new BridgeTransactionException("Failed to transmit '$incrementedFilePath': $original", 0, $error);
            }
            
            //TODO: See if this can be turned into a middleware later
            if (env('BRIDGE_DEBUG')) {
                //TODO: Support all file drivers, not just these two
                $fileHandle = fopen(storage_path('/logs/laravel.log'), 'a');
                $logHandle = app(StreamHandler::class, ['stream' => $fileHandle]);
                $logger = app(Logger::class, ['name' => 'StorageDriver', 'handlers' => [$logHandle]]);
                $fullPath = rtrim($root, '/').'/'.ltrim($incrementedFilePath, '/');
                $logger->debug("Transmitted $fullPath: Success = $success");
            }
            
            if (in_array($method, ['put']) && !$success) {
                throw new BridgeTransactionException("Failed to transmit '$incrementedFilePath'.");
            }
        }
        
        return [
            'response' => $success,
            'file_path' => $incrementedFilePath,
            'root' => $root
        ];
    }
}
