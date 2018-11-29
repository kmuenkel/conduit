<?php

namespace Conduit\Adapters;

use Storage;
use InvalidArgumentException;
use Illuminate\Support\Traits\Macroable;

/**
 * Class DayforceAdapter
 * @package Conduit\Adapters
 * @see https://www.dayforcehcm.com/api/swagger/docs/v1 for JSON-Schema describing all endpoints and their expected input
 */
class DayforceAdapter extends BaseAdapter
{
    use Macroable {
        Macroable::__call as macroCall;
    }
    
    /**
     * @var array
     */
    private $macroRefs = [];
    
    /**
     * @var array
     */
    protected static $required = [];
    
    /**
     * @var string
     */
    public static $clientNamespace = 'hireflex';
    
    /**
     * DayforceAdapter constructor.
     * @param array $config
     * @param ResponseTransformer|null $transformer
     */
    public function __construct(array $config = [], ResponseTransformer $transformer = null)
    {
        parent::__construct($config, $transformer);
        
        if (empty(self::$macros)) {
            $schemaFile = 'Dayforce_API_JSON_Schema.json';
            $driver = Storage::createLocalDriver(['root' => resource_path('assets/validationSchemas')]);
            if (!$driver->exists($schemaFile)) {
                $jsonSchema = file_get_contents('services.'.config(get_class($this).'.documentation_url'));
                $driver->put($schemaFile, $jsonSchema);
            } else {
                $jsonSchema = $driver->get($schemaFile);
            }
            $jsonSchema = collect(json_decode($jsonSchema, true));
            
            $paths = $jsonSchema->only('paths')->collapse();
            $definitions = $jsonSchema->only('definitions')->collapse()->toArray();
            
            $paths->each(function ($routes, $path) use ($definitions) {
                $path = trim($path, '/');
                
                foreach ($routes as $method => $details) {
                    $fields = collect($details)->only('parameters')->collapse()->map(function ($field) use ($definitions) {
                        if ($reference = array_get($field, 'schema.$ref')) {
                            $field['schema']['$ref'] = $this->makeDefinition($reference, $definitions);
                        }
                        return $field;
                    });
                    $required = $fields->where('required', true)->pluck('name')->toArray();
                    $contentType = collect($details)->only('consumes')->collapse()->first();
                    
                    $macro = $details['operationId'];
                    if (count(array_get($details, 'tags', [])) == 1) {
                        $macro = $method.array_first($details['tags']);
                    }
                    
                    $closure = function ( $data = [], ResponseTransformer $transformer = null) use ($required, $path, $method, $fields, $contentType, $macro) {
                        $transformer = $transformer ?: self::$transformer;
                        
                        $data['clientNamespace'] = $data['clientNamespace'] ?? self::$clientNamespace;
                        
                        if (($errors = array_keys_exist($required, $data, false, true)) !== true) {
                            throw new InvalidArgumentException('The following required parameters are missing: '.print_r($errors['missing'], true));
                        }
                        
                        $package = [
                            'parameters' => [],
                            'body' => [],
                            'query' => []
                        ];
                        
                        $fields->each(function ($field) use ($data, &$package) {
                            if (array_key_exists($field['name'], $data)) {
                                $value = $data[$field['name']];
                                if ($macro = array_get($field, 'schema.$ref')) {
                                    $package[$field['in']] = $this->$macro($value);
                                    return null;
                                }
                                $package[$field['in']][$field['name']] = $value;
                            }
                        });
                        
                        $headers = ['content-type' => $contentType];
                        
                        $response = $this->driver->send($path, $method, $package, $headers);
                        return $transformer ? $transformer->transform($response, $macro) : $response;
                    };
                    
                    if (($index = array_search('clientNamespace', $required)) !== false) {
                        unset($required[$index]);
                    }
                    
                    if (!array_key_exists($macro, self::$required)) {
                        self::$required[$macro] = [];
                    }
                    self::$required[$macro][] = $required;
                    
                    $macro = $macro.'('.implode(', ', $required).')';
                    
                    self::macro($macro, $closure);
                }
            });
        }
    }
    
    /**
     * @param $macro
     * @return array
     */
    public function getRequiredFields($macro)
    {
        return array_get(self::$required, $macro, []);
    }
    
    /**
     * @param string $ref
     * @param array $definitions
     * @return string
     */
    private function makeDefinition($ref, array $definitions = [])
    {
        $path = explode('/', preg_replace('/^\#\/definitions\//', '', $ref));
        $definition = $definitions;
        foreach ($path as $name) {
            $definition = array_get($definition, $name);
        }
        
        $macro = 'make'.studly_case(implode('_', $path));
        
        $required = $fields = [];
        if (!array_key_exists('type', $definition) || $definition['type'] == 'object') {
            $fields = collect($definition)->only('properties')->collapse();
            $required = $fields->where('required', true)->pluck('name')->toArray();
            
            $fields->each(function ($property, $name) use ($definitions, &$definition) {
                if ($reference = array_get($property, '$ref')) {
                    $existingMacro = array_get($this->macroRefs, $reference, '');
                    $definition['properties'][$name]['$ref'] = self::hasMacro($existingMacro) ?
                        $existingMacro : $this->makeDefinition($reference, $definitions);
                } elseif (array_get($property, 'type') == 'array' && ($reference = array_get($property, 'items.$ref'))) {
                    $existingMacro = array_get($this->macroRefs, $reference, '');
                    $definition['items']['$ref'] = self::hasMacro($existingMacro) ?
                        $existingMacro : $this->makeDefinition($reference, $definitions);
                }
            });
        } elseif ($definition['type'] == 'array' && ($reference = array_get($definition, 'items.$ref'))) {
            $existingMacro = array_get($this->macroRefs, $reference, '');
            $definition['items']['$ref'] = self::hasMacro($existingMacro) ?
                $existingMacro : $this->makeDefinition($reference, $definitions);
        }
        
        $closure = function (array $data = []) use ($required, $definition, $ref) {
            if (($errors = array_keys_exist($required, $data, false, true)) !== true) {
                throw new InvalidArgumentException('The following required parameters are missing: '.print_r($errors['missing'], true));
            }
            
            $package = [];
            if (array_get($definition, 'type') == 'array') {
                foreach ($data as $index => $element) {
                    $package[] = ($macro = array_get($definition, 'items.$ref')) ?
                        $this->$macro($element) : $element;
                }
            } else {
                $properties = array_get($definition, 'properties', []);
                foreach ($properties as $name => $property) {
                    if (array_key_exists($name, $data)) {
                        $package[$name] = ($marco = array_get($property, '$ref')) ?
                            $this->$marco($data[$name]) : $data[$name];
                        unset($data[$name]);
                    }
                }
                //TODO: Some fields seem to be required that aren't in the documentation
//                if (!empty($data)) {
//                    foreach ($data as $field => $value) {
//                        $package[$field] = $value;
//                    }
//                }
            }
            
            return $package;
        };
        
        self::macro($macro, $closure);
        $this->macroRefs[$ref] = $macro;
        if (!array_key_exists($macro, self::$required)) {
            self::$required[$macro] = [];
        }
        self::$required[$macro][] = $required;
        
        return $macro;
    }
    
    /**
     * @return array
     */
    public function getEntities()
    {
        $macros = array_keys(static::$macros);
        
        return $macros;
    }
    
    /**
     * @param $method
     * @param array $arguments
     * @return array
     */
    public function __call($method, array $arguments)
    {
        $parameters = array_first($arguments, null, []);
        $requiredFields = $this->getRequiredFields($method);
        $matched = 0;
        $macro = $method;
        foreach ($requiredFields as $fields) {
            if (!empty($fields) && array_keys_exist($fields, $parameters) && count($fields) >= $matched) {
                $macro = $method.'('.implode(', ', $fields).')';
                $matched = count($fields);
            }
        }
        
        return $this->macroCall($macro, $arguments);
    }
}
