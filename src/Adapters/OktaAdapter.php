<?php

namespace Conduit\Adapters;

use Ramsey\Uuid\Uuid;
use GuzzleHttp\Psr7\Response;
use function Conduit\parse_uri;
use function Conduit\resolve_uri;
use function Conduit\password_generator;
use function Conduit\parse_http_response;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpFoundation\Cookie;
use Conduit\Exceptions\BridgeTransactionException;

/**
 * Class OktaAdapter
 * @package Conduit\Adapters
 */
class OktaAdapter extends BaseAdapter
{
    /**
     * @param $uri
     * @param string $method
     * @param array $parameters
     * @param array $headers
     * @param array $cookies
     * @return array
     */
    public function send($uri, $method = 'get', $parameters = [], array $headers = [], array $cookies = [])
    {
        $headers = array_merge(['accept' => 'application/json'], array_change_key_case($headers));
        return $this->driver->toggleQueryEncoding(false)->send($uri, $method, $parameters, $headers, $cookies);
    }
    
    /**
     * @param string $search
     * @param string $query
     * @return array
     */
    public function findUsers($search, $query = 'q')
    {
        $route = 'api/v1/users';
        $method = 'GET';
        $parameters = [$query => $search];
        
        return $this->send($route, $method, $parameters);
    }
    
    /**
     * @param string $search
     * @param string $query
     * @return array
     */
    public function findGroups($search, $query = 'q')
    {
        $route = 'api/v1/groups';
        $method = 'GET';
        $parameters = [$query => $search];
        
        return $this->send($route, $method, $parameters);
    }
    
    /**
     * @param $firstName
     * @param $lastName
     * @param $email
     * @param bool $activate
     * @param string|array|null $password
     * @param null $securityQuestion
     * @param null $securityAnswer
     * @param array|null $groupIds
     * @return array
     */
    public function createUser(
        $firstName,
        $lastName,
        $email,
        $activate = true,
        $password = null,
        $securityQuestion = null,
        $securityAnswer = null,
        array $groupIds = null
    ) {
        $route = 'api/v1/users';
        $method = 'POST';
        $parameters = [
            'query' => [
                'activate' => $activate
            ],
            'body' => [
                'profile' => [
                    'firstName' => trim($firstName),
                    'lastName' => trim($lastName),
                    'email' => trim($email),
                    'login' => trim($email)
                ]
            ]
        ];
        
        $password = $password ?: compact('email');
        $password = is_array($password) ? password_generator(get_class($this), $password) : $password;
        $password = str_pad($password, 4, '_');
        
        $credentials = [];
        if ($password) {
            $password = str_pad($password, 4, '_');
            $credentials['password'] = $password;
        }
        
        if ($securityQuestion && !is_null($securityAnswer)) {
            $credentials['recovery_question'] = [
                'question' => $securityQuestion,
                'answer' => $securityAnswer
            ];
        }
        
        if (!empty($credentials)) {
            $parameters['body']['credentials'] = $credentials;
        }
        
        if (!empty($groupIds)) {
            $parameters['body']['groupIds'] = $groupIds;
        }
        
        $parameters['body'] = json_encode($parameters['body']);
        
        return $this->send($route, $method, $parameters);
    }
    
    /**
     * @param string $userId
     * @param bool $sendEmail
     * @return array
     */
    public function activateUser($userId, $sendEmail = true)
    {
        $route = 'api/v1/users/{userId}/lifecycle/activate';
        $method = 'POST';
        $parameters = [
            'path' => [
                'user_id' => $userId
            ],
            'query' => [
                'sendEmail' => $sendEmail
            ]
        ];
        
        return $this->send($route, $method, $parameters);
    }
    
    /**
     * @param string $username
     * @param string|array|null $password
     * @param string $ipAddress
     * @param string $deviceToken
     * @param string|null $relayState
     * @param array $options
     * @return array
     */
    public function authenticate($username, $password = null, $ipAddress = null, $deviceToken = null, $relayState = null, array $options = [])
    {
        $route = 'api/v1/authn';
        $method = 'POST';
        
        $defaultOptions = [
            'warnBeforePasswordExpired' => true,
            'multiOptionalFactorEnroll' => false
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        $ipAddress = $ipAddress ?: request()->header('x-forwarded-for', request()->ip());
        $host = request()->header('x-forwarded-host', config('app.url'));
        
        $deviceToken = $deviceToken ?: request()->cookie('deviceToken', md5(Uuid::uuid1()));
        cookie()->queue(app(Cookie::class, [
            'name' => 'deviceToken',
            'value' => $deviceToken,
            'domain' => $host,
            'expire' => '+ 10 YEARS'    //https://stackoverflow.com/a/3290474/2137316
        ]));
        
        $password = $password ?: compact('email');
        $password = is_array($password) ? password_generator(get_class($this), $password) : $password;
        $password = str_pad($password, 4, '_');
        
        $parameters = [
            'username' => $username,
            'password' => $password,
            'options' => $options,
            'context' => [
                'deviceToken' => $deviceToken
            ]
        ];
        $parameters['body'] = json_encode($parameters);
        
        if ($relayState) {
            $parameters['relayState'] = $relayState;
        }
        
        $headers = [
            'X-Fowarded-For' => $ipAddress
        ];
        
        try {
            $response = $this->send($route, $method, $parameters, $headers);
        } catch (BridgeTransactionException $exception) {
            $previous = $exception->getPrevious();
            if (!$previous || !($previous instanceof ClientException) || $previous->getCode() != 401) {
                throw $exception;
            }
            
            $response = $previous->getResponse();
            $response = (is_object($response) && $response instanceof Response) ?
                array_get(parse_http_response($response), 'content') : $response;
            
            $oktaErrorCode = is_object($response) ? data_get($response, 'errorCode') : null;
            if ($oktaErrorCode == 'E0000004') {
                $this->resetLogin($username, $password);
                $response = $this->send($route, $method, $parameters, $headers);
            }
        }
        
        session(['sessionToken' => collect($response)->pluck('sessionToken')->first()]);
        
        return $response;
    }
    
    /**
     * @param sting $username
     * @param sting $password
     * @return array
     */
    public function resetLogin($username, $password)
    {
        if ($user = $this->userIsLockedOut($username)) {
            $userId = data_get($user, 'id');
            $this->unlockUser($userId);
        }
        
        return $this->setPassword($username, $password);
    }
    
    /**
     * @param string $username
     * @return \StdClass|null
     */
    public function userIsLockedOut($username)
    {
        $route = 'api/v1/users?filter=status eq "LOCKED_OUT" and profile.login eq "{username}"&limit=1';
        $method = 'GET';
        $parameters = compact('username');
        
        $users = array_get($this->send($route, $method, $parameters), 'content');
        
        return array_first($users);
    }
    
    /**
     * @param string $userId
     * @return bool
     */
    public function unlockUser($userId)
    {
        $route = 'api/v1/users/{userId}/lifecycle/unlock';
        $method = 'POST';
        $parameters = [
            'path' => compact('userId')
        ];
        
        $code = array_get($this->send($route, $method, $parameters), 'code');
        
        return ($code == 200);
    }
    
    /**
     * @param string $userId
     * @param string $password
     * @return array
     */
    public function setPassword($userId, $password)
    {
        $route = 'api/v1/users/{userId}';
        $method = 'PUT';
        $parameters = [
            'path' => compact('userId'),
            'body' => json_encode([
                'credentials' => [
                    'password' => [
                        'value' => $password
                    ]
                ]
            ])
        ];
        
        $user = $this->send($route, $method, $parameters);
        
        return $user;
    }
    
    /**
     * @param $search
     * @param string $query
     * @return array
     */
    public function getUserApps($search, $query = 'user.id+eq+')
    {
        $route = 'api/v1/apps';
        $method = 'GET';
        $parameters = ['filter' => $query.'"'.$search.'"'];
        
        $apps = $this->send($route, $method, $parameters);
        
        $apps = collect($apps['content'])->mapWithKeys(function ($app) {
            $appLinkVisibility = collect($app->visibility->appLinks);
            $linkName = $appLinkVisibility->keys()->first();
            $linkVisible = $appLinkVisibility->first();
            $link = $linkVisible ? collect($app->_links->appLinks)->where('name', $linkName)->first() : [];
            $redirecUrl = collect($app->accessibility)->get('loginRedirectUrl') ?: $link->href;
            
            $logo = array_first($app->_links->logo);
            
            $credentials = $app->credentials;
            
            return [$app->name => [
                'id' => $app->id,
                'status' => $app->status,
                'label' => $app->label,
                'link' => array_get($this->config, 'sso_links.'.$app->name, $redirecUrl),
                'logo' => [
                    'href' => $logo->href,
                    'type' => $logo->type
                ],
                'credentials' => [
                    'usernameTemplate' => $credentials->userNameTemplate->template,
                    'kid' => $credentials->signing->kid
                ]
            ]];
        })->filter(function ($app) {
            return ($app['status'] == 'ACTIVE');
        })->toArray();
        
        return $apps;
    }
    
    /**
     * @param string $userId
     * @return array
     */
    public function getUserAppLinks($userId)
    {
        $route = 'api/v1/users/{userId}/appLinks';
        $method = 'GET';
        $parameters = compact('userId');
        
        $appLinks = $this->send($route, $method, $parameters);
        $appLinks = array_get($appLinks, 'content');
        $appLinks = collect($appLinks)->mapWithKeys(function ($app) {
            return [$app->id => [
                'app_id' => $app->appInstanceId,
                'label' => $app->label,
                'link' => $app->linkUrl,
                'name' => $app->appName,
                'logo' => [
                    'href' => $app->logoUrl
                ]
            ]];
        });
        
        return $appLinks->toArray();
    }
    
    /**
     * @param string $userId
     * @param string $appId
     * @param string $username
     * @return array
     */
    public function updateAppUsername($userId, $appId, $username)
    {
        $route = 'api/v1/apps/{appId}/users/{userId}';
        $method = 'POST';
        $parameters = [
            'path' => compact('appId', 'userId'),
            'body' => json_encode([
                'credentials' => [
                    'userName' => $username
                ]
            ])
        ];
        
        $userApp = $this->send($route, $method, $parameters);
        
        return $userApp;
    }
    
    /**
     * @param $appId
     * @return array
     */
    public function getApp($appId)
    {
        $route = 'api/v1/apps/{appId}';
        $method = 'GET';
        $parameters = compact('appId');
        
        return $this->send($route, $method, $parameters);
    }
    
    /**
     * @param string $userId
     * @param string|null $firstName
     * @param string|null $lastName
     * @param string|null $email
     * @param string|null $password
     * @return array
     */
    public function updateProfile($userId, $firstName = null, $lastName = null, $email = null, $password = null)
    {
        $data = ['profile' => array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'login' => $email
        ])];
        
        $password = $password ?: compact('email');
        $password = is_array($password) ? password_generator(get_class($this), $password) : $password;
        $password = str_pad($password, 4, '_');
        
        $data['credentials'] = ['password' => ['value' => $password]];
        
        $appLinks = $this->getUserAppLinks($userId);
        $appIds = array_pluck($appLinks, 'app_id');
        $userApps = collect($this->getUserApps($userId));
        
        foreach ($appIds as $appId) {
            $app = $userApps->where('id', $appId);
            $usernameTemplate = $app->pluck('credentials.usernameTemplate')->first();
            foreach ($data['profile'] as $field => $value) {
                if ($usernameTemplate == '${source.'.$field.'}') {
                    $this->updateAppUsername($userId, $appId, $value);
                }
            }
        }
        
        return $this->updateUser($userId, $data);
    }
    
    /**
     * @param $token
     * @param $target
     * @return array
     */
    public function getSessionCookie($token, $target)
    {
        $route = 'login/sessionCookieRedirect';
        $method = 'GET';
        $parameters = ['token' => $token, 'redirectUrl' => $target];
        
        return $this->send($route, $method, $parameters);
    }
    
    /**
     * @param string $token
     * @param string $target
     * @return string
     */
    public function getSessionCookieUrl($token = '{token}', $target = '{target}')
    {
        $uri = 'login/sessionCookieRedirect';
        $root = config('services.'.get_class($this).'.base_uri');
        $url = resolve_uri($uri, ['token' => $token, 'redirectUrl' => $target], $root);
//        $url = array_get(parse_uri($url), 'full');
        
        session(['sessionCookieUrl' => $url]);
        
        return $url;
    }
    
    /**
     * @param $userId
     * @param array $data
     * @return array
     */
    public function updateUser($userId, array $data)
    {
        $route = 'api/v1/users/{userId}';
        $method = 'PUT';
        $parameters = [
            'path' => [
                'user_id' => $userId
            ],
            'body' => json_encode($data)
        ];
        
        return $this->send($route, $method, $parameters);
    }
    
    //TODO: For embed links, present a SASR link instead, with the target embed in it.  the SASR endpoint will evaluate if the sessionToken already exists, and if so, send the user to the embed link.  if not, send them to the cookie redirect, using the embed as the cookie endpoint's target.  if whitelisting it is a problem, send them back to the same cookie-existence evaluation endpoint instead.  include a number of tries on the URL to avoid recursion in case something goes wrong in producing or detecting the cookie
}
