<?php

declare(strict_types=1);

namespace WebmanAuth;

use Exception;
use WebmanAuth\exception\JwtTokenException;
use WebmanAuth\facade\JWT as JwtFace;

/**
 * 认证核心类
 * 协程安全：每次请求创建新实例，无静态状态
 */
class Auth
{
    /**
     * 是否在失败时抛出异常（默认 false，返回 null/false）
     * @var bool
     */
    protected bool $fail = false;

    /**
     * 当前 guard 名称
     * @var string
     */
    protected string $guard = 'user';

    /**
     * 插件配置
     * @var array
     */
    protected array $config = [];

    /**
     * 临时覆盖 access_token 过期秒数（0 表示使用配置）
     * @var int
     */
    protected int $accessTime = 0;

    /**
     * 临时覆盖 refresh_token 过期秒数（0 表示使用配置）
     * @var int
     */
    protected int $refreshTime = 0;

    /**
     * 配置静态缓存（协程安全：只读配置数据）
     */
    protected static ?array $configCache = null;

    /**
     * 构造函数
     */
    public function __construct(array $_config = [])
    {
        if ($_config === []) {
            // 使用静态缓存避免重复读取配置（配置数据只读，协程安全）
            if (self::$configCache === null) {
                self::$configCache = config('plugin.codex13.auth.app');
            }
            $_config = self::$configCache;
        }

        if (empty($_config)) {
            throw new JwtTokenException('The configuration file is abnormal or does not exist');
        }

        $this->config = $_config;
    }

    /**
     * 设置当前角色
     * @param string $name
     * @return $this
     */
    public function guard(string $name): Auth
    {
        $this->guard = $name;
        return $this;
    }

    /**
     * 判断是否使用 ThinkORM 查询风格
     * @return bool
     */
    public function isThinkOrm(): bool
    {
        $database = config('database');
        if (isset($database['default']) && str_starts_with($database['default'], 'plugin.')) {
            $database = false;
        }
        $thinkorm = config('thinkorm');
        if (isset($thinkorm['default']) && str_starts_with($thinkorm['default'], 'plugin.')) {
            $thinkorm = false;
        }
        return !$database && $thinkorm;
    }

    /**
     * 临时设置 access_token 过期时间（秒）
     * @param int $num
     * @return $this
     */
    public function accessTime(int $num): Auth
    {
        $this->accessTime = $num;
        return $this;
    }

    /**
     * 临时设置 refresh_token 过期时间（秒）
     * @param int $num
     * @return $this
     */
    public function refreshTime(int $num): Auth
    {
        $this->refreshTime = $num;
        return $this;
    }


    /**
     * 设置失败行为
     * true: 抛异常；false: 返回 null/false
     * @param bool $error
     * @return Auth
     */
    public function fail(bool $error = true): Auth
    {
        $this->fail = $error;
        return $this;
    }

    /**
     * 按传入字段自动查询并登录
     * @param array $data
     * @return false|mixed
     */
    public function attempt(array $data): mixed
    {
        try {
            $user = $this->getUserClass();
            if ($user == null) throw new JwtTokenException('模型不存在', 400);
            foreach ($data as $key => $val) {
                if ($key !== 'password') {
                    $user = $user->where($key, $val);
                }
            }

            if ($this->isThinkOrm()) {
                $user = $user->find();
            } else {
                $user = $user->first();
            }

            if ($user != null) {
                if (isset($data['password'])) {
                    if (!password_verify($data['password'], $user->password)) {
                        throw new JwtTokenException('密码错误', 400);
                    }
                }
                return $this->login($user);
            }
            throw new JwtTokenException('账号或密码错误', 400);
        } catch (JwtTokenException $e) {
            if ($this->fail) {
                throw new JwtTokenException($e->getMessage(), $e->getCode());
            }
            return false;
        }
    }

    /**
     * 获取当前 guard 对应模型实例
     * @return object|null
     */
    protected function getUserClass(): ?object
    {
        $guardConfig = $this->config['guard'][$this->guard]['model'] ?? null;
        if (!empty($guardConfig)) {
            return new $guardConfig;
        }
        return null;
    }

    /**
     * 获取当前用户
     * $cache=true 返回 token 扩展字段；否则查库返回模型对象
     * @param bool $cache
     * @return mixed|null
     */
    public function user(bool $cache = false): mixed
    {
        try {
            $key = $this->config['guard'][$this->guard]['key']; // 主键字段名
            $extend = JwtFace::guard($this->guard)->getTokenExtend();
            if (!empty($extend->extend) && isset($extend->extend->$key)) {
                if ($cache) {
                    return $extend->extend;
                } else {
                    $user = $this->getUserClass();
                    if ($this->isThinkOrm()) {
                        return $user->where($key, $extend->extend->$key)->find();
                    } else {
                        return $user->where($key, $extend->extend->$key)->first();
                    }
                }

            }
            throw new JwtTokenException('配置信息异常', 401);
        } catch (JwtTokenException $e) {
            if ($this->fail) {
                throw new JwtTokenException($e->getMessage(), $e->getCode());
            }
            return null;
        }
    }

    /**
     * 登录并生成令牌
     *
     * @param mixed $data
     * @return object|null
     */
    public function login(mixed $data): ?object
    {
        $fields = $this->config['guard'][$this->guard]['field']; // 允许写入 token 扩展字段
        $idKey = $this->config['guard'][$this->guard]['key']; // 主键字段名
        $newData = [];
        // 仅保留白名单字段写入 token 扩展
        if (is_object($data)) {
            foreach ($fields as $key) {
                $newData[$key] = $data->$key ?? null;
            }
        } elseif (is_array($data) && count($data) > 0) {
            foreach ($fields as $key) {
                $newData[$key] = $data[$key] ?? null;
            }
        }

        try {
            if (!isset($newData[$idKey])) {
                throw new JwtTokenException('缺少必要主键', 400);
            }
            return JwtFace::guard($this->guard)->make($newData, $this->accessTime, $this->refreshTime);
        } catch (JwtTokenException $e) {
            if ($this->fail) { // 失败时按配置抛异常
                throw new JwtTokenException($e->getError(), $e->getCode());
            }
            return null;
        }
    }

    /**
     * 刷新 access_token
     * @return false|object
     */
    public function refresh(): false|object
    {
        try {
            return JwtFace::guard($this->guard)->refresh($this->accessTime);
        } catch (JwtTokenException $e) {
            if ($this->fail) { // 失败时按配置抛异常
                throw new JwtTokenException($e->getError(), $e->getCode());
            }
            return false;
        }
    }

    /**
     * 退出登录
     * @param bool $all
     * @return bool
     */
    public function logout(bool $all = false): bool
    {
        try {
            JwtFace::guard($this->guard)->logout($all);
            return true;
        } catch (JwtTokenException $e) {
            if ($this->fail) { // 失败时按配置抛异常
                throw new JwtTokenException($e->getError(), $e->getCode());
            }
            return false;
        }
    }

    /**
     * 预留：生成 JWT 密钥
     * @return void
     * @throws Exception
     */
    public function jwtKey(): void
    {

    }

    /**
     * 使用 bcrypt 进行密码哈希
     * @param string $password
     * @return string|null
     */
    public function bcrypt(string $password): ?string
    {
        $cost = config('plugin.codex13.auth.app.jwt.bcrypt_cost', 10);
        $cost = is_numeric($cost) ? (int)$cost : 10;
        $cost = max(4, min(31, $cost));

        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => $cost,
        ]);
    }

    /**
     * 动态方法转发（isXxx -> is('xxx')）
     * @param string $method
     * @param array $args
     * @return bool
     */
    public function __call(string $method, array $args): bool
    {
        if ('is' == strtolower(substr($method, 0, 2))) {
            $method = substr($method, 2);
        }

        $args[] = lcfirst($method);

        return call_user_func_array([$this, 'is'], $args);
    }

}
