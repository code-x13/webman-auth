<?php

declare(strict_types=1);

namespace WebmanAuth;

use Exception;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as jwtMan;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use WebmanAuth\exception\JwtTokenException;
use WebmanAuth\facade\Str;
use support\Redis;
use UnexpectedValueException;
use function bcadd;

/**
 * JWT 令牌管理类
 * 协程安全：每次请求创建新实例，无静态状态
 */
class JWT
{
    /**
     * 令牌类型常量
     */
    const REFRESH = 2, ACCESS = 1;

    /**
     * 当前 guard 名称
     */
    protected string $guard = 'user';

    /**
     * JWT 配置
     */
    protected array $config = [];

    /**
     * 是否启用 Redis 会话限制
     */
    protected bool $redis = false;

    /**
     * 配置静态缓存（协程安全：只读配置数据）
     */
    protected static ?array $configCache = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 使用静态缓存避免重复读取配置（配置数据只读，协程安全）
        if (self::$configCache === null) {
            self::$configCache = config('plugin.codex13.auth.app.jwt');
        }

        $_config = self::$configCache;

        if (empty($_config)) {
            throw new JwtTokenException('The configuration file is abnormal or does not exist');
        }

        $this->config = $_config;
        $this->redis = $this->toBool($_config['redis'] ?? false);
        $this->config['redis'] = $this->redis;
    }

    /**
     * 归一化布尔配置，支持 "true"/"false"/1/0/on/off/yes/no。
     * @param mixed $value
     * @return bool
     */
    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }
        return (bool)$value;
    }

    /**
     * 设置当前 guard
     * @param string $guard
     * @return $this
     */
    public function guard(string $guard = 'user'): JWT
    {
        $this->guard = $guard;
        return $this;
    }

    /**
     * 生成 access_token + refresh_token
     * @param array $extend
     * @param int $access_exp
     * @param int $refresh_exp
     * @return object
     * @throws Exception
     */
    public function make(array $extend, int $access_exp = 0, int $refresh_exp = 0): object
    {
        $exp = $access_exp > 0 ? $access_exp : $this->config['access_exp'];
        $refreshExp = $refresh_exp > 0 ? $refresh_exp : $this->config['refresh_exp'];
        $payload = self::payload($extend, $exp, $refreshExp);
        $secretKey = self::getPrivateKey();
        $accessToken = self::makeToken($payload['accessPayload'], $secretKey, $this->config['algorithms']);

        $refreshSecretKey = self::getPrivateKey(self::REFRESH);
        $refreshToken = self::makeToken($payload['refreshPayload'], $refreshSecretKey, $this->config['algorithms']);

        // 获取主键字段
        $idKey = config("plugin.codex13.auth.app.guard.{$this->guard}.key");
        // Redis 开启时写入终端会话
        if ($this->redis) {
            $this->setRedis($extend[$idKey], $accessToken, $refreshToken, $exp, $refreshExp);
            // 同时写入 session，便于无 Header 场景读取
        }
        session()->set(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $accessToken);
        return json_decode(json_encode([
            'token_type' => 'Bearer',
            'expires_in' => $exp,
            'refresh_expires_in' => $refreshExp,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]));
    }

    /**
     * 使用 refresh_token 刷新 access_token
     * @param int $accessTime
     * @return object|null
     * @throws Exception
     */
    public function refresh(int $accessTime = 0): ?object
    {
        $token = $this->getTokenFormHeader();
        $tokenPayload = (array)self::verifyToken($token, self::REFRESH);
        $tokenPayload['exp'] = time() + ($accessTime > 0 ? $accessTime : $this->config['access_exp']);
        $secretKey = $this->getPrivateKey();
        $newToken = $this->makeToken($tokenPayload, $secretKey, $this->config['algorithms']);
        $tokenObj = json_decode(json_encode(['access_token' => $newToken]));
        if ($this->redis) {
            // 获取主键字段
            $idKey = config("plugin.codex13.auth.app.guard.{$this->guard}.key");
            $this->setRedis($tokenPayload['extend']->$idKey, $tokenObj->access_token, $token, $this->config['access_exp'], $this->config['refresh_exp']);
        }
        return $tokenObj;
    }

    /**
     * 从 Header/参数/session 获取 token
     * @return string
     * @throws Exception
     */
    protected function getTokenFormHeader(): string
    {
        $header = request()->header('Authorization', '');
        $token = request()->input('_token');
        if (Str::startsWith($header, 'Bearer ')) {
            $token = Str::substr($header, 7);
        }
        if (!empty($token) && Str::startsWith($token, 'Bearer ')) {
            $token = Str::substr($token, 7);
        }
        $token = $token ?? session(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}");
        if (empty($token)) {
            $token = null;
            $fail = new JwtTokenException('尝试获取的 Authorization 信息不存在');
            $fail->setCode(401);
            throw $fail;
        }
        return $token;
    }

    /**
     * 验证令牌
     * @param string|null $token
     * @param int $tokenType
     * @return object|null
     * @throws JwtTokenException|Exception
     */
    public function verify(?string $token = null, int $tokenType = self::ACCESS): ?object
    {
        $token = $token ?? $this->getTokenFormHeader();
        return $this->verifyToken($token, $tokenType);
    }

    /**
     * 验证 token 字符串并返回负载
     * @param string $token
     * @param int $tokenType
     * @return object|null
     */
    public function verifyToken(string $token, int $tokenType): ?object
    {
        $secretKey = self::ACCESS == $tokenType ? $this->getPublicKey($this->config['algorithms']) : $this->getPublicKey($this->config['algorithms'], self::REFRESH);
        jwtMan::$leeway = 60;
        try {
            $tokenPayload = jwtMan::decode($token, new Key($secretKey, $this->config['algorithms']));
            if ($tokenPayload->guard != $this->guard) {
                throw new SignatureInvalidException('无效令牌');
            }
            // Redis 开启时校验 token 是否仍有效
            if ($this->redis) {
                // 获取主键字段
                $idKey = config("plugin.codex13.auth.app.guard.{$this->guard}.key");
                $this->checkRedis($tokenPayload->extend->$idKey, $token, $tokenType);
            }
            return $tokenPayload;
        } catch (SignatureInvalidException $e) {
            throw new JwtTokenException('身份验证令牌无效', 401);
        } catch (BeforeValidException $e) { // token 未到生效时间
            throw new JwtTokenException('身份验证令牌尚未生效', 403);
        } catch (ExpiredException $e) { // token 已过期
            throw new JwtTokenException('身份验证会话已过期，请重新登录！', 402);
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new JwtTokenException('获取扩展字段不正确', 401);
        } catch (Exception $exception) {
            throw new JwtTokenException($exception->getMessage(), 401);
        }

    }

    /**
     * 获取令牌负载（含 extend 字段）
     * @param string|null $token
     * @param int $tokenType
     * @return object|null
     * @throws JwtTokenException|Exception
     */
    public function getTokenExtend(?string $token = null, int $tokenType = self::ACCESS): ?object
    {
        return $this->verify($token, $tokenType);
    }

    /**
     * 根据载荷签发 token
     * @param array $payload
     * @param string $secretKey
     * @param string $algorithms
     * @return string
     */
    public function makeToken(array $payload, string $secretKey, string $algorithms): string
    {
        try {
            return jwtMan::encode($payload, $secretKey, $algorithms);
        } catch (ExpiredException $e) { // 令牌时间字段不合法
            throw new JwtTokenException('签名不正确', 401);
        } catch (Exception $e) { // 其他签发异常
            throw new JwtTokenException('其它错误', 401);
        }
    }

    /**
     * 组装 access/refresh 两套 payload
     * @param array $extend
     * @param int $access_exp
     * @param int $refresh_exp
     * @return array
     */
    public function payload(array $extend, int $access_exp = 0, int $refresh_exp = 0): array
    {
        $basePayload = [
            'iss' => $this->config['iss'],
            'iat' => time(),
            'exp' => time() + $access_exp,
            'extend' => $extend,
            'guard' => $this->guard
        ];
        $resPayLoad['accessPayload'] = $basePayload;
        $basePayload['exp'] = time() + $refresh_exp;
        $resPayLoad['refreshPayload'] = $basePayload;
        return $resPayLoad;
    }

    /**
     * 根据算法获取验签密钥（公钥或共享密钥）
     * @param string $algorithm
     * @param int $tokenType
     * @return string
     */
    protected function getPublicKey(string $algorithm, int $tokenType = self::ACCESS): string
    {
        return match ($algorithm) {
            'HS256' => self::ACCESS == $tokenType ? $this->config['access_secret_key'] : $this->config['refresh_secret_key'],
            'RS512', 'RS256' => self::ACCESS == $tokenType ? $this->config['access_public_key'] : $this->config['refresh_public_key'],
            default => $this->config['access_secret_key'],
        };
    }

    /**
     * 根据算法获取签名密钥（私钥或共享密钥）
     * @param int $tokenType
     * @return string
     */
    protected function getPrivateKey(int $tokenType = self::ACCESS): string
    {
        return match ($this->config['algorithms']) {
            'HS256' => self::ACCESS == $tokenType ? $this->config['access_secret_key'] : $this->config['refresh_secret_key'],
            'RS512', 'RS256' => self::ACCESS == $tokenType ? $this->config['access_private_key'] : $this->config['refresh_private_key'],
            default => $this->config['access_secret_key'],
        };
    }

    /**
     * 退出登录并清理当前会话
     * @param bool $all
     * @throws Exception
     */
    public function logout(bool $all = false): void
    {
        $token = $this->getTokenFormHeader();
        $tokenPayload = self::verifyToken($token, self::ACCESS);
        // Redis 开启时同步删除 Redis 侧会话
        if (isset($this->config['redis']) && $this->config['redis']) {

            // 获取主键字段
            $idKey = config("plugin.codex13.auth.app.guard.{$this->guard}.key");
            $id = $tokenPayload->extend->$idKey;
            if ($all) {
                Redis::hDel(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
            } else {
                $list = Redis::hGet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
                if ($list) {
                    $tokenList = unserialize($list);
                    foreach ($tokenList as $key => $val) {
                        if ($val['accessToken'] == $token) {
                            unset($tokenList[$key]);
                        }
                    }
                    if (count($tokenList) == 0) {
                        Redis::hDel(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
                    } else {
                        Redis::hSet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id, serialize($tokenList));
                    }
                }
            }

        }
        // 清理 session 中缓存的 token
        session()->forget(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}");
    }

    /**
     * 写入 Redis 终端会话
     * @param int $id
     * @param string $accessToken
     * @param string $refreshToken
     * @param int $accessExp
     * @param int $refreshExp
     */
    protected function setRedis(int $id, string $accessToken, string $refreshToken, int $accessExp, int $refreshExp): void
    {
        $list = Redis::hGet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
        $clientType = strtolower(request()->input('client_type', 'web'));
        $defaultList = [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'clientType' => $clientType,
            'accessExp' => $accessExp,
            'refreshExp' => $refreshExp,
            'refreshTime' => time(),
            'accessTime' => time(),
        ];
        if ($list != null) {
            $tokenList = unserialize($list);
            $maxNum = config("plugin.codex13.auth.app.guard.{$this->guard}.num");
            if (is_array($tokenList)) {
                if ($maxNum === -1) { // 不限制终端数量
                    $this->updateOrAppendToken($id, $tokenList, $accessToken, $refreshToken, $accessExp, $defaultList);
                } elseif ($maxNum === 0) { // 仅保留一个终端
                    Redis::hSet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id, serialize([$defaultList]));
                } elseif ($maxNum > 0) { // 限制同一 clientType 终端数量
                    $clientTypeNum = 0;
                    $index = -1;
                    foreach ($tokenList as $key => $val) {
                        if ($val['clientType'] == $clientType) {
                            $clientTypeNum++;
                            $index < 0 && $index = $key;
                        }
                    }
                    if ($index >= 0 && $clientTypeNum >= $maxNum) {
                        unset($tokenList[$index]);
                    }
                    $this->updateOrAppendToken($id, $tokenList, $accessToken, $refreshToken, $accessExp, $defaultList);
                }
                // 追加后清理已过期 refresh 会话
                $this->clearExpRedis($id);
            }
        } else {
            Redis::hSet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id, serialize([$defaultList]));
        }
    }

    /**
     * 更新或追加 token 到列表
     * @param int $id 用户 ID
     * @param array $tokenList 令牌列表（引用传递）
     * @param string $accessToken 访问令牌
     * @param string $refreshToken 刷新令牌
     * @param int $accessExp 访问令牌过期时间
     * @param array $defaultList 默认令牌数据
     */
    protected function updateOrAppendToken(int $id, array &$tokenList, string $accessToken, string $refreshToken, int $accessExp, array $defaultList): void
    {
        $match = false;
        foreach ($tokenList as &$item) {
            if ($item['refreshToken'] === $refreshToken) {
                $match = true;
                $item['accessToken'] = $accessToken;
                $item['accessExp'] = $accessExp;
                $item['accessTime'] = $defaultList['accessTime'];
                break;
            }
        }
        !$match && $tokenList[] = $defaultList;
        Redis::hSet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id, serialize($tokenList));
    }

    /**
     * 清理已过期 refresh_token 会话
     * @param int $id
     */
    public function clearExpRedis(int $id): void
    {
        $list = Redis::hGet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
        if ($list) {
            $tokenList = unserialize($list);
            $refresh = false;
            foreach ($tokenList as $key => $val) {
                if (($val['refreshTime'] + $val['refreshExp']) < time()) {
                    unset($tokenList[$key]);
                    $refresh = true;
                }
            }
            if (count($tokenList) == 0) {
                Redis::hDel(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
            } else {
                if ($refresh) {
                    Redis::hSet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id, serialize($tokenList));
                }
            }
        }
    }

    /**
     * 校验 token 是否存在于 Redis 会话中且未过期
     * @param int $id
     * @param string $token
     * @param int $tokenType
     */
    public function checkRedis(int $id, string $token, int $tokenType = self::ACCESS): void
    {
        $list = Redis::hGet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
        if ($list != null) {
            $tokenList = unserialize($list);
            $checkToken = false;
            $expireToken = false;
            foreach ($tokenList as $key => $val) {
                if ($tokenType == self::REFRESH && $val['refreshToken'] == $token) {
                    if (($val['refreshTime'] + $val['refreshExp']) < time()) {
                        unset($tokenList[$key]);
                    } else {
                        $checkToken = true;
                    }
                }
                if ($tokenType == self::ACCESS && $val['accessToken'] == $token) {
                    if (($val['accessTime'] + $val['accessExp']) < time()) {
                        $expireToken = true;
                    } else {
                        $checkToken = true;
                    }
                }
            }
            if (count($tokenList) == 0) {
                Redis::hDel(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id);
            } else {
                Redis::hSet(config('plugin.codex13.auth.app.cache_key') . "{$this->guard}", $id, serialize($tokenList));
            }
            if (!$checkToken) {
                if ($expireToken) {
                    throw new ExpiredException('无效');
                } else {
                    throw new SignatureInvalidException('无效');
                }
            }
        } else {
            throw new SignatureInvalidException('无效');
        }
    }

    /**
     * 动态方法转发（isXxx -> is('xxx')）
     * @param string $method 方法名
     * @param array $args 参数
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
