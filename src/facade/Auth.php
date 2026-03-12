<?php

declare (strict_types = 1);
namespace WebmanAuth\facade;

/**
 * Class Auth
 * @package WebmanAuth\Facade
 * @see \WebmanAuth\Auth
 * @mixin \WebmanAuth\Auth
 * @method guard(string $name) static 设置 guard
 * @method login($data,int $access_time = 0,int $refresh_time = 0) static 登录
 * @method refresh() static 刷新 access_token
 * @method logout() static 退出登录
 * @method fail(bool $error = true) static 设置失败行为（是否抛异常）
 * @method attempt(array $data) static 自动校验字段并登录
 * @method jwtKey() static 预留：生成 jwt 密钥
 * @method bcrypt($password) static bcrypt 密码哈希
 */
class Auth
{
    public static function instance(): \WebmanAuth\Auth
    {
        return new \WebmanAuth\Auth();
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
