<?php

declare (strict_types = 1);
namespace WebmanAuth\facade;

/**
 * Class Auth
 * @package WebmanAuth\Facade
 * @see \WebmanAuth\JWT
 * @mixin \WebmanAuth\JWT
 * @method make(array $extend,int $access_exp = 0,int $refresh_exp = 0) static 生成 access/refresh 令牌
 * @method refresh(int $accessTime) static 刷新 access_token
 * @method guard($guard = 'user') static 设置 guard
 * @method verify(string $token = null, int $tokenType = 1) static 验证 token
 * @method verifyToken(string $token,int $tokenType) static 验证 token 字符串
 * @method getTokenExtend(string $token = null, int $tokenType = 1) static 获取 token 负载
 * @method makeToken(array $payload, string $secretKey, string $algorithms) static 签发 token 字符串
 * @method logout($all = false) static 退出
 *
 */
class JWT
{
    public static function instance(): \WebmanAuth\JWT
    {
        return new \WebmanAuth\JWT();
    }
    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::instance()->{$name}(... $arguments);
    }
}
