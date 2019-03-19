<?php

namespace Conduit\Tests;

use Artisan;
use Tymon\JWTAuth\JWTAuth;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use InvalidArgumentException;
use Tymon\JWTAuth\JWTManager;
use Laravel\Passport\Passport;
use App\Services\Eloquent\Model;
use Tymon\JWTAuth\PayloadFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Query\Builder;
use Laravel\Passport\Guards\TokenGuard;
use App\Services\JWTAuth\PayloadValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\PersonalAccessTokenFactory;
use Tymon\JWTAuth\Facades\JWTAuth as JWTAuthFacade;

/**
 * Class AuthTokenHelper
 * @package Tests
 */
class AuthTokenHelper
{
    /**
     * @param Authenticatable|Model $model
     * @param array $scope
     * @param bool $personalAccessToken
     * @return mixed
     */
    public function makeOauthLoginToken(Authenticatable $model = null, array $scope = ['*'], $personalAccessToken = true)
    {
        $tokenName = $clientName = 'testing';
        Artisan::call('passport:client', ['--personal' => true, '--name' => $clientName]);
        if (!$personalAccessToken) {
            $clientId = app(Client::class)->where('name', $clientName)->first(['id'])->id;
            Passport::$personalAccessClient = $clientId;
        }
        $userId = $model->getKey();
        return app(PersonalAccessTokenFactory::class)->make($userId, $tokenName, $scope)->accessToken;
    }
    
    /**
     * @param Authenticatable $model
     * @return string
     */
    public function makeJwtLoginToken(Authenticatable $model)
    {
        return JWTAuthFacade::fromUser($model);
    }
    
    /**
     * @param string $token
     * @param Authenticatable|Model|null $model
     * @return array
     */
    public function parseJwtToken($token, Authenticatable $model = null)
    {
        $validator = app(PayloadValidator::class);
        
        $payloadFactory = new PayloadFactory(
            app('tymon.jwt.claim.factory'),
            app('request'),
            $validator
        );
        
        $manager = new JWTManager(
            app('tymon.jwt.provider.jwt'),
            app('tymon.jwt.blacklist'),
            $payloadFactory
        );
        
        $jwtAuth = new JWTAuth(
            $manager,
            app('tymon.jwt.provider.user'),
            app('tymon.jwt.provider.auth'),
            app('request')
        );
        
        $token = preg_replace('/^Bearer /', '', $token);
        $payload = $jwtAuth->getPayload($token);
        $subject = $payload->getSubject();
        
        if (!$model) {
            //Find the default model being used for user data
            $defaultGuard = config('auth.defaults.guard');
            $guardProvider = config("auth.guards.$defaultGuard.provider");
            $model = config("auth.providers.$guardProvider.model");
            $model = $model ? app($model) : $model;
        }
        
        if ($model) {
            $user = $model->find((int)$subject);
        }
        
        $payload = $payload->toArray();
        
        if (isset($user)) {
            $payload['user'] = $user;
        }
        
        return $payload;
    }
    
    /**
     * @param string $token
     * @param Authenticatable|Model $model
     * @return Authenticatable|Model|null
     */
    public function parsePassportToken($token, Authenticatable $model = null)
    {
        if (!$model) {
            $provider = config('auth.guards.passport.provider');
            $model = config("auth.providers.$provider.model");
            $model = app($model);
        }
        //Passport's token parsing is looking to a bearer token using a protected method.  So a dummy-request is needed.
        $request = app(Request::class);
        $request->headers->add(['authorization' => "Bearer $token"]);
        //Laravel\Passport\Guards\TokenGuard::authenticateViaBearerToken() expects the user table to leverage the
        //HasApiTokens trait.  If that's missing, a query macro can satisfy its expectation for this method.
        if (!method_exists($model, 'withAccessToken')) {
            Builder::macro('withAccessToken', function ($accessToken) use ($model) {
                $model->accessToken = $accessToken;
                return $this;
            });
            try {
                /** @var TokenGuard $guard */
                $guard = Auth::guard('passport');
            } catch (InvalidArgumentException $error) {
                return null;
            }
            $user = $guard->user($request);
            return $user ? $user->getModel() : null;
        }
        /** @var TokenGuard $guard */
        $guard = Auth::guard('passport');
        return $guard->user($request);
    }
}
