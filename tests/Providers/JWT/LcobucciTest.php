<?php

/*
 * This file is part of jwt-auth.
 *
 * (c) Sean Tymon <tymon148@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tymon\JWTAuth\Test\Providers\JWT;

use InvalidArgumentException;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token;
use Mockery;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Providers\JWT\Lcobucci;
use Tymon\JWTAuth\Test\AbstractTestCase;

class LcobucciTest extends AbstractTestCase
{
    /**
     * @var \Mockery\MockInterface
     */
    protected $parser;

    /**
     * @var \Mockery\MockInterface
     */
    protected $token;

    /**
     * @var \Mockery\MockInterface
     */
    protected $builder;

    /**
     * @var \Tymon\JWTAuth\Providers\JWT\Namshi
     */
    protected $provider;

    public function setUp(): void
    {
        parent::setUp();

        $this->builder = Mockery::mock(Builder::class);
        $this->parser = Mockery::mock(Parser::class);
        $this->token = Mockery::mock(Token::class);
    }

    /** @test */
    public function it_should_return_the_token_when_passing_a_valid_payload_to_encode()
    {
        $payload = ['sub' => 1, 'exp' => $this->testNowTimestamp + 3600, 'iat' => $this->testNowTimestamp, 'iss' => '/foo'];
        $this->builder->shouldReceive('unsign')->once()->andReturnSelf();
        $this->builder->shouldReceive('set')->times(count($payload));

        $token = $this->getProvider('secret', 'HS256')->encode($payload);

        self::assertSame('foo.bar.baz', $token);
    }

    /** @test */
    public function it_should_throw_an_invalid_exception_when_the_payload_could_not_be_encoded()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('Could not create token:');

        $payload = ['sub' => 1, 'exp' => $this->testNowTimestamp, 'iat' => $this->testNowTimestamp, 'iss' => '/foo'];

        $this->builder->shouldReceive('unsign')->once()->andReturnSelf();
        $this->builder->shouldReceive('set')->times(count($payload));

        $this->getProvider('secret', 'HS256')->encode($payload);
    }

    /** @test */
    public function it_should_return_the_payload_when_passing_a_valid_token_to_decode()
    {
        $payload = ['sub' => 1, 'exp' => $this->testNowTimestamp + 3600, 'iat' => $this->testNowTimestamp, 'iss' => '/foo'];

        $this->parser->shouldReceive('parse')->once()->with('foo.bar.baz')->andReturn(Mockery::self());
        $this->token->shouldReceive('verify')->once()
            ->with(Mockery::any(), Mockery::type(Key::class))
            ->andReturn(true);
        $this->token->shouldReceive('getClaims')->once()->andReturn($payload);

        self::assertSame($payload, $this->getProvider('secret', 'HS256')->decode('foo.bar.baz'));
    }

    /** @test */
    public function it_should_throw_a_token_invalid_exception_when_the_token_could_not_be_decoded_due_to_a_bad_signature()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Token Signature could not be verified.');

        $this->parser->shouldReceive('parse')->once()->with('foo.bar.baz')->andReturn(Mockery::self());
        $this->token->shouldReceive('verify')->once()
            ->with(Mockery::any(), Mockery::type(Key::class))
            ->andReturn(false);
        $this->token->shouldReceive('getClaims')->never();

        $this->getProvider('secret', 'HS256')->decode('foo.bar.baz');
    }

    /** @test */
    public function it_should_throw_a_token_invalid_exception_when_the_token_could_not_be_decoded()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Could not decode token:');

        $this->parser->shouldReceive('parse')->once()->with('foo.bar.baz')->andThrow(new InvalidArgumentException);
        $this->parser->shouldReceive('verify')->never();
        $this->parser->shouldReceive('getClaims')->never();

        $this->getProvider('secret', 'HS256')->decode('foo.bar.baz');
    }

    /** @test */
    public function it_should_generate_a_token_when_using_an_rsa_algorithm()
    {
        $provider = $this->getProvider(
            'does_not_matter',
            'RS256',
            ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        $payload = ['sub' => 1, 'exp' => $this->testNowTimestamp + 3600, 'iat' => $this->testNowTimestamp, 'iss' => '/foo'];

        $this->builder->shouldReceive('unsign')->once()->andReturnSelf();
        $this->builder->shouldReceive('set')->times(count($payload));
        $this->builder->shouldReceive('getToken')->once()
            ->with(Mockery::any(), Mockery::type(Key::class))
            ->andReturn('foo.bar.baz');

        $token = $provider->encode($payload);

        self::assertSame('foo.bar.baz', $token);
    }

    /** @test */
    public function it_should_throw_a_exception_when_the_algorithm_passed_is_invalid()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('The given algorithm could not be found');

        $this->parser->shouldReceive('parse')->never();
        $this->parser->shouldReceive('verify')->never();

        $this->getProvider('secret', 'AlgorithmWrong')->decode('foo.bar.baz');
    }

    /** @test */
    public function it_should_return_the_public_key()
    {
        $provider = $this->getProvider(
            'does_not_matter',
            'RS256',
            $keys = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        self::assertSame($keys['public'], $provider->getPublicKey());
    }

    /** @test */
    public function it_should_return_the_keys()
    {
        $provider = $this->getProvider(
            'does_not_matter',
            'RS256',
            $keys = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        self::assertSame($keys, $provider->getKeys());
    }

    public function getProvider($secret, $algo, array $keys = [])
    {
        return new Lcobucci($this->builder, $this->parser, $secret, $algo, $keys);
    }

    public function getDummyPrivateKey()
    {
        return file_get_contents(__DIR__.'/../Keys/id_rsa');
    }

    public function getDummyPublicKey()
    {
        return file_get_contents(__DIR__.'/../Keys/id_rsa.pub');
    }
}
