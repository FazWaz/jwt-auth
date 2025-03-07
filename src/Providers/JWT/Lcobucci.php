<?php

/*
 * This file is part of jwt-auth.
 *
 * (c) Sean Tymon <tymon148@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tymon\JWTAuth\Providers\JWT;

use Exception;
use Illuminate\Support\Collection;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Ecdsa;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as ES256;
use Lcobucci\JWT\Signer\Ecdsa\Sha384 as ES384;
use Lcobucci\JWT\Signer\Ecdsa\Sha512 as ES512;
use Lcobucci\JWT\Signer\Hmac\Sha256 as HS256;
use Lcobucci\JWT\Signer\Hmac\Sha384 as HS384;
use Lcobucci\JWT\Signer\Hmac\Sha512 as HS512;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RS256;
use Lcobucci\JWT\Signer\Rsa\Sha384 as RS384;
use Lcobucci\JWT\Signer\Rsa\Sha512 as RS512;
use Lcobucci\JWT\Token\RegisteredClaims;
use ReflectionClass;
use Tymon\JWTAuth\Claims\Expiration;
use Tymon\JWTAuth\Claims\IssuedAt;
use Tymon\JWTAuth\Claims\Issuer;
use Tymon\JWTAuth\Claims\JwtId;
use Tymon\JWTAuth\Claims\NotBefore;
use Tymon\JWTAuth\Claims\Subject;
use Tymon\JWTAuth\Contracts\Providers\JWT;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class Lcobucci extends Provider implements JWT
{
    /**
     * The Builder instance.
     *
     * @var \Lcobucci\JWT\Builder
     */
    protected $builder;

    /**
     * The Parser instance.
     *
     * @var \Lcobucci\JWT\Parser
     */
    protected $parser;

    /**
     * @var \Lcobucci\JWT\Signer
     */
    protected $signer;

    /**
     * Create the Lcobucci provider.
     *
     * @param  \Lcobucci\JWT\Builder  $builder
     * @param  \Lcobucci\JWT\Parser  $parser
     * @param  string  $secret
     * @param  string  $algo
     * @param  array  $keys
     *
     * @return void
     */
    public function __construct(
        Builder $builder,
        Parser $parser,
        $secret,
        $algo,
        array $keys
    ) {
        parent::__construct($secret, $algo, $keys);

        $this->builder = $builder;
        $this->parser = $parser;
        $this->signer = $this->getSigner();
    }

    /**
     * Signers that this provider supports.
     *
     * @var array
     */
    protected $signers = [
        'HS256' => HS256::class,
        'HS384' => HS384::class,
        'HS512' => HS512::class,
        'RS256' => RS256::class,
        'RS384' => RS384::class,
        'RS512' => RS512::class,
        'ES256' => ES256::class,
        'ES384' => ES384::class,
        'ES512' => ES512::class,
    ];

    /**
     * Create a JSON Web Token.
     *
     * @param  array  $payload
     *
     * @throws \Tymon\JWTAuth\Exceptions\JWTException
     *
     * @return string
     */
    public function encode(array $payload)
    {
        // Remove the signature on the builder instance first.
        try {
            $signingKey = $this->getSigningKey();
            $signingKey = is_string($signingKey) ? Key\InMemory::plainText($signingKey) : $signingKey;
            if (!empty($payload[RegisteredClaims::AUDIENCE])) {
                $this->builder->permittedFor($payload[RegisteredClaims::AUDIENCE]);
            }
            $this->builder
                ->expiresAt((new Expiration($payload[RegisteredClaims::EXPIRATION_TIME]))->getValue())
                ->identifiedBy((new JwtId($payload[RegisteredClaims::ID]))->getValue())
                ->issuedAt((new IssuedAt($payload[RegisteredClaims::ISSUED_AT]))->getValue())
                ->issuedBy((new Issuer($payload[RegisteredClaims::ISSUER]))->getValue())
                ->canOnlyBeUsedAfter((new NotBefore($payload[RegisteredClaims::NOT_BEFORE]))->getValue())
                ->relatedTo((new Subject($payload[RegisteredClaims::SUBJECT]))->getValue());

            foreach ($payload as $key => $value) {
                $this->builder->withHeader($key, $value);

                if (!in_array($key, RegisteredClaims::ALL, true)) {
                    $this->builder->withClaim($key, $value);
                }
            }

            return $this->builder->getToken($this->signer, $signingKey)->toString();
        } catch (Exception $e) {
            throw new JWTException('Could not create token: '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Decode a JSON Web Token.
     *
     * @param  string  $token
     *
     * @throws \Tymon\JWTAuth\Exceptions\JWTException
     *
     * @return array
     */
    public function decode($token)
    {
        try {
            $jwt = $this->parser->parse($token);
        } catch (Exception $e) {
            throw new TokenInvalidException('Could not decode token: '.$e->getMessage(), $e->getCode(), $e);
        }

        $verificationKey = $this->getVerificationKey();
        $verificationKey = is_string($verificationKey) ? Key\InMemory::plainText($verificationKey) : $verificationKey;

        if (!$this->signer->verify($jwt->signature()->hash(), $jwt->payload(), $verificationKey)) {
            throw new TokenInvalidException('Token Signature could not be verified.');
        }

        return (new Collection($jwt->claims()->all()))->map(function ($claim) {
            return is_object($claim) ? $claim : $claim;
        })->toArray();
    }

    /**
     * Get the signer instance.
     *
     * @throws \Tymon\JWTAuth\Exceptions\JWTException
     *
     * @return \Lcobucci\JWT\Signer
     */
    protected function getSigner()
    {
        if (! array_key_exists($this->algo, $this->signers)) {
            throw new JWTException('The given algorithm could not be found');
        }

        return new $this->signers[$this->algo];
    }

    /**
     * {@inheritdoc}
     */
    protected function isAsymmetric()
    {
        $reflect = new ReflectionClass($this->signer);

        return $reflect->isSubclassOf(Rsa::class) || $reflect->isSubclassOf(Ecdsa::class);
    }

    /**
     * {@inheritdoc}
     */
    protected function getSigningKey()
    {
        return $this->isAsymmetric() ?
            Key\InMemory::file($this->getPrivateKey(), $this->getPassphrase() ?? '') :
            $this->getSecret();
    }

    /**
     * {@inheritdoc}
     */
    protected function getVerificationKey()
    {
        return $this->isAsymmetric() ?
            Key\InMemory::file($this->getPublicKey()) :
            $this->getSecret();
    }
}
