<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


class AuthService
{

    // const passphrase = '[YOUR_PASSPHRASE]';

    public static function createToken($user)
    {

        $key = config('app.key');

        $payload = [
            'alg' => 'HS256',
            'iss' => config('app.name'), // Issuer of the token
            'sub' => $user->id, // Subject of the token
            'iat' => time(), // Time when JWT was issued.
            'nbf' => time(), // Time before which the JWT must not be accepted for processing
            'exp' => time() + 60*60*24*7, // Expiration time of the token (7 days)
            'role' => $user->role,
        ];

        $jwt = JWT::encode($payload, $key, 'HS256');

        return $jwt;

    }

    function decodeToken($token)
    {
        $key = config('app.key');

        /**
         * You can add a leeway to account for when there is a clock skew times between
         * the signing and verifying servers. It is recommended that this leeway should
         * not be bigger than a few minutes.
         *
         * Source: http://self-issued.info/docs/draft-ietf-oauth-json-web-token.html#nbfDef
         */
        JWT::$leeway = 60; // $leeway in seconds

        return JWT::decode($token, new Key($key, 'HS256'));
    }

    // verifyToken
    public static function verifyToken($token)
    {
        $key = config('app.key');

        $decoded = JWT::decode($token, new Key($key, 'HS256'));

        return $decoded;
    }


}



