<?php

namespace Conduit;

use StdClass;
use Exception;
use DOMElement;
use DOMDocument;
use ErrorException;
use LogicException;
use SimpleXMLElement;
use Tests\AuthTokenHelper;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use InvalidArgumentException;
use Illuminate\Routing\Router;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\RequestInterface;
use Illuminate\Database\Eloquent\Model;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Cookie\CookieJarInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Foundation\Testing\TestResponse;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

if (!function_exists('guess_content_type')) {
    /**
     * @param mixed $content
     * @return string
     */
    function guess_content_type($content) : string
    {
        $contentType = gettype($content);
        
        switch ($contentType) {
            case 'array':
                $contentType = 'application/x-www-form-urlencoded';
                break;
            case 'integer':
            case 'double':
            case 'boolean':
                $contentType = 'text/plain';
                break;
            case 'object':
                $contentType = 'application/x-www-form-urlencoded';
                if ($content instanceof Arrayable) {
                    $contentType = 'application/json';
                } elseif ($content instanceof StdClass) {
                    $contentType = 'application/json';
                } elseif ($content instanceof SimpleXMLElement) {
                    $contentType = 'application/xml';
                    $content = $content->asXML();
                    if (htmlentities($content) == $content) {
                        $contentType = 'text/html';
                    }
                }
                break;
            case 'string':
                $contentType = 'text/plain';
                
                if (!$content) {
                    break;
                }
                
                json_decode($content, true);
                $contentType = (json_last_error() == JSON_ERROR_NONE) ? 'application/json' : $contentType;
                
                libxml_use_internal_errors(true);
                libxml_clear_errors();
                $xmlLoaded = false;
                $errors = [];
                $contentType = (
                    $contentType == 'text/plain'
                    && ($xmlLoaded = simplexml_load_string($content)) !== false
                    && empty($errors = libxml_get_errors())
                ) ? 'application/xml' : $contentType;
                libxml_clear_errors();
                
                if ($contentType == 'application/xml') {
                    $xmlDocument = new DOMDocument();
                    $xmlDocument->loadXML($content);
                    if (isset($xmlDocument->doctype) && $xmlDocument->doctype->nodeName == 'html') {
                        $contentType = 'text/html';
                    }
                } elseif ($contentType == 'text/plain') {
                    $document = new DOMDocument();
                    $document->loadHTML($content);
                    $htmlProbability = 0;
                    if (strlen($content) < 10000) {
                        similar_text($document->saveHTML(), $content, $htmlProbability);
                        if ($htmlProbability > 95) {
                            $contentType = 'text/html';
                        }
                    } elseif ($xmlLoaded
                        && count($errors)
                        && array_get(array_first($errors, null, []), 'message') != "Start tag expected, '<' not found\n"
                    ) {
                        $contentType = 'text/html';
                    }
                }
                
                if ($contentType == 'text/plain' && strlen($content) <= ini_get('max_input_vars')) {
                    parse_str($content, $query);
                    $query = array_filter($query);
                    if (!empty($query)) {
                        $contentType = 'application/x-www-form-urlencoded';
                        return $contentType;
                    }
                }
                
//                if (base64_encode(base64_decode($content)) === $content) {
//                    //TODO: Add support for base64 encoded file streams.  May need to extract the MIME-Type, or set the header to application/octet-stream
//                }
        }
        
        return $contentType;
    }
}

if (!function_exists('parse_http_content')) {
    /**
     * @param $content
     * @param string|null $contentType
     * @return StdClass|SimpleXMLElement|string
     */
    function parse_http_content($content, string $contentType = null)
    {
        if (!$content) {
            return '';
        }
        
        $contentType = $contentType ?: guess_content_type($content);
        $contentTypes = explode(',', $contentType);
        
        if (is_string($content)) {
            foreach ($contentTypes as $contentType) {
                switch ($contentType) {
                    case 'application/json':
                        return json_decode($content);
                        break;
                    case 'application/xml':
                        $document = new DOMDocument();
                        $document->loadXML($content);
                        return $document;
                        break;
                    case 'text/html':
                        $document = new DOMDocument();
                        $document->loadHTML($content);
                        return $document;
                        break;
                    case 'application/x-www-form-urlencoded':
                        $query = $content;
                        if (is_string($content)) {
                            parse_str($content, $query);
                        }
                        return $query;
                    case 'text/plain':
                        return $content;
                    default:
                        return $content;
                    #return '';
                }
            }
        }
        
        return $content;
    }
}

if (!function_exists('prime_http_content')) {
    /**
     * @param mixed $content
     * @param string|null $contentType
     * @param bool $asResponse
     * @return array|StdClass|string
     */
    function prime_http_content($content, string $contentType = null, bool $asResponse = false)
    {
        $contentType = $contentType ?: guess_content_type($content);
        
        if (is_object($content)) {
            if ($content instanceof Arrayable) {
                $content = $content->toArray();
                return $asResponse ? json_encode($content) : $content;
            } elseif ($content instanceof StdClass) {
                return $asResponse ? json_encode($content) : (array)$content;
            } elseif ($content instanceof SimpleXMLElement) {
                return $content->asXML();
            } elseif ($content instanceof DOMDocument) {
                return ($contentType == 'text/html') ? $content->saveHTML() : $content->saveXML();
            }
        } elseif (is_string($content) && $contentType == 'application/json' && !$asResponse) {
            return json_decode($content, true);
        } elseif ($asResponse) {
            return is_array($content) ? json_encode($content) : (string)$content;
        }
        
        return $content;
    }
}

if (!function_exists('flatten_array_keys')) {
    /**
     * @param array $query
     * @return array
     */
    function flatten_array_keys(array $query)
    {
        $query = http_build_query($query);
        $query = urldecode($query);
        
        return $query;
    }
}

if (!function_exists('parse_http_request')) {
    /**
     * @param RequestInterface|Request|null $request
     * @param bool $asArray
     * @return array
     */
    function parse_http_request($request = null, $asArray = false) : array
    {
        $request = $request ?: request();
        $uri = parse_uri((string)$request->getUri());
        $uri['method'] = $request->getMethod();
        
        if ($request instanceof Request) {
            $originalHeaders = $request->headers->all();
            $headers = parse_http_headers($originalHeaders);
            $body = (string)$request->getContent();
        } elseif ($request instanceof  RequestInterface) {
            $originalHeaders = $request->getHeaders();
            $headers = parse_http_headers($originalHeaders);
            $body = (string)$request->getBody();
        } else {
            throw new \InvalidArgumentException('Argument 1 must be an instance of '.RequestInterface::class.' or '.Request::class);
        }
        
        $routes = app(Router::class)->getRoutes()->get($uri['method']);
        $paths = array_keys($routes);
        $pathPatterns = [];
        foreach ($paths as $index => $routePath) {
            $pathPatterns[$index] = preg_replace('/\{.+?\}/', '*', $routePath);
        }
        
        $path = trim(array_get($uri, 'path', ''), '/');
        $route = null;
        foreach ($pathPatterns as $index => $pathPattern) {
            if (fnmatch($pathPattern, $path)) {
                $path = $paths[$index];
                $route = $routes[$path];
            }
        }
        
        /** @var Route|null $route */
        $route = $route ? $route->getAction() : [];
        
        $contentType = array_get($originalHeaders, 'content-type', array_get($originalHeaders, 'accept'));
        if (is_array($contentType)) {
            $contentType = array_first($contentType);
        }
        $body = parse_http_content($body, $contentType);
        
        if ($asArray && is_object($body)) {
            $body = object_to_array($body);
        }
        
        return compact('uri', 'headers', 'body', 'route');
    }
}

if (!function_exists('parse_http_response')) {
    /**
     * @param TestResponse|HttpResponse|SymfonyResponse|ResponseInterface $response
     * @param CookieJarInterface|null $cookies
     * @return array
     */
    function parse_http_response($response, CookieJarInterface $cookies = null) : array
    {
        if (!(
            $response instanceof TestResponse
            || $response instanceof HttpResponse
            || $response instanceof SymfonyResponse
            || $response instanceof ResponseInterface
        )) {
            $given = is_object($response) ? get_class($response) : gettype($response);
            throw new InvalidArgumentException(
                'Argument 1 passed to ' . __METHOD__ . ' must be an instance of '
                . TestResponse::class . ', '
                . HttpResponse::class . ', '
                . SymfonyResponse::class . ', or '
                . ResponseInterface::class . '.  '
                . $given . ' given.'
            );
        }
        
        $headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : $response->headers->all();
        $headers = parse_http_headers($headers);
        
        $content = '';
        if ($response instanceof ResponseInterface) {
            $content = (string)$response->getBody();
        } elseif ($response instanceof SymfonyResponse
            || $response instanceof HttpResponse
            || $response instanceof TestResponse
        ) {
            $content = $response->getContent();
        }
        
//        $contentType = (array)array_get($headers, 'content-type');
//        $contentType = current($contentType);
//        $content = parse_http_content($content, $contentType);
        $content = parse_http_content($content);
        $code = $response->getStatusCode();
        
        $cookies = $cookies ? $cookies->toArray() : [];
        
        $cookieHeaders = array_get($headers, 'set-cookie', []);
        
        $keys = array_keys($cookieHeaders);
        $numericKeys = array_keys($keys);
        if ($keys !== $numericKeys) {
            $cookieHeaders = [$cookieHeaders];
        }
        
        foreach ($cookieHeaders as $cookie) {
            $cookieString = [];
            foreach ($cookie as $index => $part) {
                $cookieString[] = is_int($index) ? $part : "$index=$part";
            }
            $cookieString = implode(';', $cookieString);
            //Symfony\Component\HttpFoundation\Cookie::fromString($cookieString)->toArray() is also an option here.
            $cookie = SetCookie::fromString($cookieString)->toArray();
            $cookies[] = $cookie;
        }
        
        $cookies = array_map('array_change_key_case', $cookies);
        
        //TODO: the following returns an array of Symfony\Component\HttpFoundation\Cookie.  Make sure it's available with all possible response objects before leveraging it in place of the above logic though thought.
        //$response->headers->getCookies();
        
        return compact('content', 'code', 'headers', 'cookies');
    }
}

if (!function_exists('parse_http_headers')) {
    /**
     * @param array $headers
     * @return array
     */
    function parse_http_headers(array $headers) : array
    {
        return collect($headers)->mapWithKeys(function ($header, $name) {
            if (!is_int($name)) {
                $name = str_slug(trim($name));
                $header = collect($header)->map(function ($header) use ($name) {
                    $header = collect(explode(';', $header))->mapWithKeys(function ($header, $key) use ($name) {
                        $url = parse_uri(trim($header, '<> '));
                        if (isset($url['scheme'])) {
                            return $url;
                        }
                        if ($name == 'authorization') {
                            if (preg_match('/Basic (.*\={2})*/i', $header)) {
                                $parsedToken = base64_decode(preg_replace('/(Basic )(.*\={2})*/i', "$2", $header));
                                list($username, $password) = array_pad(explode(':', $parsedToken), 2, null);
                                $parsedToken = array_filter(compact('username', 'password'));
                                $header = !empty($parsedToken) ? ['basic' => $parsedToken] : [$key => $header];
                            } elseif (preg_match('/Bearer (.+)/i', $header)) {
                                $tokenHelper = class_exists(AuthTokenHelper::class) ? app(AuthTokenHelper::class) : null;
                                $parsedToken = preg_replace('/(Bearer )(.+)/i', "$2", $header);
                                if ($tokenHelper && $parsedToken) {
                                    try {
                                        $jwtToken = $tokenHelper->parseJwtToken($parsedToken);
                                        foreach ($jwtToken as $tokenIndex => $tokenPart) {
                                            if (in_array($tokenIndex, ['iat', 'exp', 'nbf']) && $tokenPart) {
                                                $jwtToken[$tokenIndex] = date('Y-m-d H:i:s', $tokenPart);
                                            } elseif (is_object($tokenPart) && $tokenPart instanceof Arrayable) {
                                                $jwtToken[$tokenIndex] = [get_class($tokenPart) => $tokenPart->toArray()];
                                            } elseif ($tokenIndex === 'iss' && $tokenPart) {
                                                $jwtToken[$tokenIndex] = parse_uri($tokenPart);
                                            }
                                        }
                                    } catch (TokenInvalidException $e) {
                                        $jwtToken = null;
                                    }
                                    
                                    $oauthToken = null;
                                    if (!$jwtToken) {
                                        try {
                                            $oauthToken = $tokenHelper->parsePassportToken($parsedToken);
                                        } catch (LogicException $e) {
                                            $oauthToken = null;
                                        }
                                    }
                                    
                                    $parsedToken = $jwtToken ?: $oauthToken;
                                    
                                    $header = $parsedToken ? ['bearer' => $parsedToken] : [$key => "Bearer $header"];
                                } else {
                                    $header = [$header];
                                }
                            } else {
                                $header = [$header];
                            }
                            
                            return $header;
                        }
                        
                        $header = explode('=', $header);
                        $key = trim($key);
                        $value = trim($header[0], '" ');
                        if (count($header) > 1) {
                            $key = trim($header[0]);
                            $value = trim($header[1], '" ');
                        }
                        parse_str("0=$value", $parsed);
                        $value = current($parsed);
                        
                        try {
                            $value = decrypt($value);
                        } catch (DecryptException $error) {
                            //The header may not actually be encrypted, so don't worry if it can't be decrypted
                        } catch (ErrorException $error) {
                            //This comes up specifically if the Horizon page is open, and pinging the server with an outdated laravel_session token cookie.
                        }
                        
                        return [$key => $value];
                    })->toArray();
                    if (count($header) == 1 && isset($header[0])) {
                        $header = $header[0];
                    }
                    return $header;
                });
                if (count($header) == 1 && isset($header[0])) {
                    $header = $header[0];
                }
                return [$name => $header];
            }
            return [];
        })->filter()->toArray();
    }
}

if (!function_exists('resolve_uri')) {
    /**
     * @param $uri
     * @param array $parameters
     * @param string|null $root
     * @param bool $encode
     * @return string
     */
    function resolve_uri($uri, array $parameters = [], $root = null, $encode = true) : string
    {
        $uri = preg_replace_callback('/\{(.*?)\}/', function ($match) use (&$parameters) {
            $key = snake_case($match[1]);
            $value = '';
            if (array_key_exists($key, $parameters)) {
                $value = $parameters[$key];
                unset($parameters[$key]);
            } elseif (array_key_exists($match[1], $parameters)) {
                $value = $parameters[$match[1]];
                unset($parameters[$match[1]]);
            } elseif (!ends_with($match[1], '?')) {
                throw new InvalidArgumentException("Missing route parameter '$key'");
            }
            return $value;
        }, $uri);
        
        $uriParts = parse_uri($uri);
        if (is_null($root) || $root) {
            $root = rtrim((string)$root ?: app(HttpRequest::class)->root(), '/');
            if ($host = array_get($uriParts, 'host')) {
                $scheme = array_get($uriParts, 'scheme', 'http');
                $root = "$scheme://$host";
            }
        }
        
        $root = (string)$root;
        $uriParts['query'] = array_merge(array_get($uriParts, 'query', []), $parameters);
        $path = trim(array_get($uriParts, 'path'), '/');
        $absolute = ($root && preg_match('/^\//', $path)) ? '/' : '';
        $query = http_build_query($uriParts['query']);
        $query = $encode ? $query : urldecode($query);
        
        $url = $absolute . trim($root . ($path ? "/$path" : '') . ($query ? '?' . $query : ''), '/');
        
        return $url;
    }
}

if (!function_exists('parse_uri')) {
    /**
     * @param $url
     * @return array
     */
    function parse_uri($url) : array
    {
        $parts = parse_url($url);
        
        $queryString = '';
        if (array_key_exists('query', $parts)) {
            $query = [];
            parse_str(urldecode($parts['query']), $query);
            $parts['query'] = $query;
            $queryString .= '?'.urldecode(http_build_query($parts['query']));
        }
        
        $parts['full'] = (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            . ($parts['host'] ?? '')
            . ($parts['path'] ?? '')
            . $queryString;
        
        return $parts;
    }
}

if (!function_exists('external_service_password_generator')) {
    /**
     * @param string $service
     * @param Model|null $model
     * @return string
     */
    function external_service_password_generator($service, Model $model = null) : string
    {
        $password = config("services.$service.password", '');
        if (preg_match_all('/(\{)(.+?)(\})/', $password, $matches)) {
            foreach ($matches[2] as $match) {
                $relations = explode('.', $match);
                $passwordPart = $model;
                foreach ($relations as $relation) {
                    $passwordPart = $passwordPart->$relation;
                }
                $password = str_replace('{'.$match.'}', $passwordPart, $password);
            }
        }
        
        if ($password && ($algo = config("services.$service.password_algo"))) {
            if (is_string($algo)) {
                $password = call_user_func($algo, $password);
            } elseif (is_callable($algo)) {
                $password = $algo($password);
            }
        }
        
        return $password;
    }
}

if (!function_exists('password_generator')) {
    /**
     * @param string $service
     * @param array $params
     * @return string
     */
    function password_generator($service, array $params) : string
    {
        $params = array_dot($params);
        $password = config("services.$service.password", '');
        if (preg_match_all('/(\{)(.+?)(\})/', $password, $matches)) {
            foreach ($matches[2] as $match) {
                $passwordPart = array_get($params, $match);
                $password = str_replace('{'.$match.'}', $passwordPart, $password);
            }
        }
        
        if ($password && ($algo = config("services.$service.password_algo"))) {
            if (is_string($algo)) {
                $password = call_user_func($algo, $password);
            } elseif (is_callable($algo)) {
                $password = $algo($password);
            }
        }
        
        return $password;
    }
}

if (!function_exists('xml_to_array')) {
    /**
     * https://stackoverflow.com/questions/14553547/what-is-the-best-php-dom-2-array-function/14554381#14554381
     *
     * @param DOMElement $root
     * @return array
     */
    function xml_to_array(DOMElement $root) : array
    {
        static $instance = 0;
        
        $result = [];
        
        if ($root->hasAttributes()) {
            $attributes = $root->attributes;
            foreach ($attributes as $attribute) {
                $result['@attributes'][$attribute->name] = $attribute->value;
            }
        }
        
        if ($root->hasChildNodes()) {
            $children = $root->childNodes;
            if ($children->length == 1) {
                $child = $children->item(0);
                if (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE])) {
                    $result['_value'] = $child->nodeValue;
                    return $result;
                }
            }
            
            $me = __FUNCTION__;
            foreach ($children as $child) {
                //TODO: This may still need to allow an empty tag
                if ($child->nodeType == XML_TEXT_NODE && empty(trim($child->nodeValue))) {
                    continue;
                }
                
                if (!isset($result[$child->nodeName])) {
                    $instance++;
                    $nestedResult = $me($child);
                    $nestedResult = (count($nestedResult) == 1 && array_key_exists('_value', $nestedResult)) ?
                        $nestedResult['_value'] : $nestedResult;
                    $result[$child->nodeName] = [$nestedResult];
                    $instance--;
                } else {
                    if (!array_key_exists($child->nodeName, $result)) {
                        $result[$child->nodeName] = [$result[$child->nodeName]];
                    }
                    $instance++;
                    $nestedResult = $me($child);
                    $nestedResult = (count($nestedResult) == 1 && array_key_exists('_value', $nestedResult)) ?
                        $nestedResult['_value'] : $nestedResult;
                    $result[$child->nodeName][] = $nestedResult;
                    $instance--;
                }
            }
            
            foreach ($result as $index => $element) {
                if (is_array($element) && count($element) < 2) {
                    $result[$index] = current($element);
                }
            }
        }
        
        return $instance ? $result : [$root->nodeName => $result];
    }
}

if (!function_exists('array_to_xml')) {
    /**
     * @param array $fields
     * @return DOMDocument
     */
    function array_to_xml(array $fields): DOMDocument
    {
        $attributes = ['1.0', 'UTF-8'];
        if (count($fields) == 2 && array_key_exists('@attributes', $fields)) {
            $attributes = $fields['@attributes'];
            unset($fields['@attributes']);
        }
        
        if (count($fields) > 1) {
            $fields = ['Root' => $fields];
        }
        
        $document = new DOMDocument(...$attributes);
        $document->formatOutput = true;
        
        return ($me = function (array $fields = [], DOMDocument $document = null, DOMElement $parent = null, $nodeName = null) use (&$me) {
            $parent = $parent ?: $document;
            
            foreach ($fields as $field => $value) {
                $attributes = [];
                if (is_array($value) && array_key_exists('@attributes', $value)) {
                    $attributes = $value['@attributes'];
                    unset($value['@attributes']);
                    if (count($value) == 1 && array_key_exists('_value', $value)) {
                        $value = $value['_value'];
                    }
                }
                
                if (is_array($value)) {
                    $element = $parent;
                    
                    if (!is_numeric_array($value, false)) {
                        if (is_int($field)) {
                            $field = $nodeName;
                        }
                        $nodeName = str_singular($field);
                        $element = $document->createElement($field);
                        $parent->appendChild($element);
                    } else {
                        $nodeName = str_singular($field);
                    }
                    
                    $me($value, $document, $element, $nodeName);
                } else {
                    $element = $document->createElement($field, $value);
                    $parent->appendChild($element);
                }
                
                foreach ($attributes as $name => $attribute) {
                    $element->setAttribute($name, $attribute);
                }
            }
            
            return $document;
        })($fields, $document);
    }
}

if (!function_exists('is_numeric_array')) {
    /**
     * @param array $array
     * @param bool $sequential
     * @return bool
     */
    function is_numeric_array(array $array, $sequential = true)
    {
        $keys = array_keys($array);
        
        if (!$sequential) {
            return empty(array_filter($keys, function ($key) {
                return !is_numeric($key);
            }));
        }
        
        $numericKeys = array_keys($keys);
        return ($keys === $numericKeys);
    }
}

if (!function_exists('get_mime_map')) {
    /**
     * @return array
     * @throws FileNotFoundException
     * @throws Exception
     */
    function get_mime_map()
    {
        $mimeTypeFilePath = __DIR__ . '/../resources/mime.types';
        $cacheKey = 'mime_map';
        $mimeArray = cache($cacheKey, []);
        
        if (empty($mimeArray)) {
            $content = \File::get($mimeTypeFilePath);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                preg_match('/([\w\+\-\.\/]+)\t+([\w\s]+)/i', $line, $matches);
                
                if (substr($line, 0, 1) == '#' || empty($matches)) {
                    continue;
                }
                
                $mime = $matches[1];
                $extensions = explode(' ', $matches[2]);
                
                foreach ($extensions as $ext) {
                    $mimeArray[trim($ext)] = $mime;
                }
            }
            
            cache()->forever($cacheKey, $mimeArray);
        }
        
        return $mimeArray;
    }
}

if (!function_exists('get_extensions_from_mime_type')) {
    /**
     * @param string $mimeType
     * @return array
     * @throws FileNotFoundException
     */
    function get_extensions_from_mime_type($mimeType)
    {
        $mimeMap = get_mime_map();
        
        $extensions = array_keys($mimeMap, $mimeType);
        if (empty($extensions) && strpos($mimeType, '*') !== false) {
            $extensions = array_keys(array_filter($mimeMap, function ($type) use ($mimeType) {
                return fnmatch($mimeType, $type);
            }));
        }
        
        return $extensions;
    }
}

if (!function_exists('object_to_array')) {
    /**
     * @param $object
     * @return array
     */
    function object_to_array($object)
    {
        if (!is_object($object)) {
            $error = 'Argument 1 of '.__FUNCTION__.' must be an object.  '.gettype($object).' given.';
            throw new InvalidArgumentException($error);
        }
        
        return json_decode(json_encode($object), true);
    }
}
