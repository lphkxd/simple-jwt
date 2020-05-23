<?php

declare(strict_types=1);
/**
 * This file is part of qbhy/simple-jwt.
 *
 * @link     https://github.com/qbhy/simple-jwt
 * @document https://github.com/qbhy/simple-jwt/blob/master/README.md
 * @contact  qbhy0715@qq.com
 * @license  https://github.com/qbhy/simple-jwt/blob/master/LICENSE
 */
namespace Qbhy\SimpleJwt;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\FilesystemCache;
use Qbhy\SimpleJwt\Encoders\Base64UrlSafeEncoder;
use Qbhy\SimpleJwt\EncryptAdapters\Md5Encrypter;
use Qbhy\SimpleJwt\Exceptions\InvalidTokenException;
use Qbhy\SimpleJwt\Exceptions\SignatureException;
use Qbhy\SimpleJwt\Exceptions\TokenBlacklistException;
use Qbhy\SimpleJwt\Exceptions\TokenExpiredException;
use Qbhy\SimpleJwt\Exceptions\TokenNotActiveException;
use Qbhy\SimpleJwt\Exceptions\TokenRefreshExpiredException;
use Qbhy\SimpleJwt\Interfaces\Encoder;
use Qbhy\SimpleJwt\Interfaces\Encrypter;

class JWTManager
{
    /** @var int token 有效期,单位分钟 minutes */
    protected $ttl = 60 * 60;

    /** @var int token 过期多久后可以被刷新,单位分钟 minutes */
    protected $refreshTtl = 120 * 60;

    /** @var AbstractEncrypter */
    protected $encrypter;

    /** @var Encoder */
    protected $encoder;

    /** @var Cache */
    protected $cache;

    /**
     * JWTManager constructor.
     *
     * @param AbstractEncrypter|string $secret
     */
    public function __construct($secret, ?Encoder $encoder = null, ?Cache $cache = null)
    {
        $this->encrypter = self::encrypter($secret, Md5Encrypter::class);
        $this->encoder = $encoder ?? new Base64UrlSafeEncoder();
        $this->cache = $cache ?? new FilesystemCache(sys_get_temp_dir());
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * 单位：分钟
     * @return $this
     */
    public function setTtl(int $ttl): JWTManager
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function getRefreshTtl(): int
    {
        return $this->refreshTtl;
    }

    /**
     * 单位：分钟
     * @return $this
     */
    public function setRefreshTtl(int $ttl): JWTManager
    {
        $this->refreshTtl = $ttl;

        return $this;
    }

    public function getEncrypter(): Encrypter
    {
        return $this->encrypter;
    }

    public function getEncoder(): Encoder
    {
        return $this->encoder;
    }

    /**
     * 创建一个 jwt.
     */
    public function make(array $payload, array $headers = []): JWT
    {
        $payload = array_merge($this->initPayload(), $payload);

        $jti = hash('md5', base64_encode(json_encode([$payload, $headers])) . $this->getEncrypter()->getSecret());

        $payload['jti'] = $jti;

        return new JWT($this, $headers, $payload);
    }

    /**
     * 一些基础参数.
     */
    public function initPayload(): array
    {
        $timestamp = time();

        return [
            'sub' => '1',
            'iss' => 'http://' . ($_SERVER['SERVER_NAME'] ?? '') . ':' . ($_SERVER['SERVER_PORT'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'exp' => $timestamp + $this->getTtl() * 60,
            'iat' => $timestamp,
            'nbf' => $timestamp,
        ];
    }

    /**
     * 解析一个jwt.
     * @throws Exceptions\InvalidTokenException
     * @throws Exceptions\SignatureException
     * @throws Exceptions\TokenExpiredException
     */
    public function parse(string $token): JWT
    {
        $encoder = $this->getEncoder();
        $encrypter = $this->getEncrypter();
        $arr = explode('.', $token);

        if (count($arr) !== 3) {
            throw new InvalidTokenException('Invalid token');
        }

        $headers = @json_decode($encoder->decode($arr[0]), true);
        $payload = @json_decode($encoder->decode($arr[1]), true);

        $signatureString = "{$arr[0]}.{$arr[1]}";

        if (! is_array($headers) || ! is_array($payload)) {
            throw new InvalidTokenException('Invalid token');
        }

        if ($encrypter->check($signatureString, $encoder->decode($arr[2]))) {
            $jwt = new JWT($this, $headers, $payload);
            $timestamp = time();
            $payload = $jwt->getPayload();

            if (isset($payload['exp']) && $payload['exp'] <= $timestamp) {
                throw (new TokenExpiredException('Token expired'))->setJwt($jwt);
            }

            if (isset($payload['nbf']) && $payload['nbf'] > $timestamp) {
                throw (new TokenNotActiveException('Token not active'))->setJwt($jwt);
            }

            $blacklistCacheKey = 'jwt.blacklist:' . ($payload['jti'] ?? $token);
            if ($this->cache->contains($blacklistCacheKey)) {
                throw (new TokenBlacklistException('The token is already on the blacklist'))->setJwt($jwt);
            }

            return $jwt;
        }

        throw new SignatureException('Invalid signature');
    }

    public function addBlacklist($jti)
    {
        $blacklistCacheKey = 'jwt.blacklist:' . $jti;
        $now = time();
        $this->cache->save($blacklistCacheKey, $now, $now + $this->getRefreshTtl() * 60);
    }

    public function removeBlacklist($jti)
    {
        $this->cache->delete('jwt.blacklist:' . $jti);
    }

    /**
     * @throws Exceptions\JWTException
     * @return JWT
     */
    public function refresh(JWT $jwt, bool $force = false)
    {
        $payload = $jwt->getPayload();

        if (! $force && isset($payload['iat'])) {
            $refreshExp = $payload['iat'] + $this->getRefreshTtl() * 60;

            if ($refreshExp <= time()) {
                throw (new TokenRefreshExpiredException('token expired, refresh is not supported'))->setJwt($jwt);
            }
        }

        unset($payload['exp'], $payload['iat'], $payload['nbf']);

        return $this->make($payload, $jwt->getHeaders());
    }

    /**
     * @param $secret
     * @param string $defaultEncrypterClass
     */
    public static function encrypter($secret, string $default = Md5Encrypter::class): Encrypter
    {
        if ($secret instanceof Encrypter) {
            return $secret;
        }
        return new $default($secret);
    }
}
