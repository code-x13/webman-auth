# 安装

```
composer require codex13/webman-auth
```
# 配置文件
```
// 配置路径：config/plugin/codex13/auth/app.php
// jwt 可按业务调整；redis=false 表示不启用 Redis 终端会话限制
// 多 guard 配置示例

// 最小示例（请替换为你的真实模型）
'guard' => [
     'user' => [
         'key' => 'id', // 主键字段
         'field' => ['id','name','email','mobile'], // 写入 token extend 的字段白名单
         'num' => 0, // -1 不限制；0 单端在线；>0 同一 client_type 最大在线终端数
         'model'=> app\model\Test::class
     ]
];
// 完整示例（user 为默认 guard，必须存在）
// field 会写入 token extend，请勿放入敏感字段
'guard' => [
     'user' => [ // 普通用户
         'key' => 'id', // 主键字段
         'field' => ['id','username','email','mobile','avatar'], // 写入 token extend 的字段白名单
         'num' => 0, // -1 不限制；0 单端在线；>0 同一 client_type 最大在线终端数
         'model'=> app\model\User::class // 用户模型
     ],
     'admin' => [ // 平台用户
         'key' => 'id', // 主键字段
         'field' => ['id','name','avatar'], // 写入 token extend 的字段白名单
         'num' => 0, // -1 不限制；0 单端在线；>0 同一 client_type 最大在线终端数
         'model'=> app\model\Admin::class // 管理员模型
     ]
];
```
# 使用方法

1. 生成JWT密钥(命令行)

```
php webman auth:key

```
执行后会自动更新 `config/plugin/codex13/auth/app.php` 中的 `access_secret_key` 和 `refresh_secret_key`。
2. 加密密码

```php
use WebmanAuth\facade\Auth;

// bcrypt 为不可逆哈希，只能用 password_verify 校验
$password = '123456';
Auth::bcrypt($password);

```
3.自动对字段进行验证且登入

```php
use WebmanAuth\facade\Auth;

// 字段需与当前 guard 模型可查询字段匹配
// attempt 会自动查库校验；不适用时请使用自定义登录
$tokenObject = Auth::attempt(['name'=> 'tycoonSong','password' => '123456']);

// 返回对象包含：token_type、expires_in、refresh_expires_in、access_token、refresh_token
    
// 默认 guard 为 user，admin 登录示例
$tokenObject = Auth::guard('admin')->attempt(['name'=> 'tycoonSong','password' => '123456']);

```

4.自定义登入

```php
use WebmanAuth\facade\Auth;
use app\model\User;
use app\model\Admin;
// 返回对象包含：token_type、expires_in、refresh_expires_in、access_token、refresh_token

$user = User::first();
$tokenObject = Auth::login($user); // $user 可为对象或数组
    
    
// 默认 guard 为 user，admin 登录示例
$admin = Admin::first();
$tokenObject = Auth::guard('admin')->login($admin);

```

5.获取当前登入用户信息

```php
    use WebmanAuth\facade\Auth;
     $user = Auth::user(); // 返回模型对象（查库）
     $user = Auth::user(true); // 返回 token 扩展字段（不查库）
     $admin = Auth::guard('admin')->user(); // 当前登录管理员
 
```

6.退出登入

```php
    use WebmanAuth\facade\Auth;
     $logout = Auth::logout(); // 退出当前终端
     $logout = Auth::logout(true); // 退出该用户全部终端
     $logout = Auth::guard('admin')->logout(); // 管理员退出
 
```

7.刷新当前登入用户token

```php
     use WebmanAuth\facade\Auth;
     $refresh = Auth::refresh();
     $refresh = Auth::guard('admin')->refresh(); // 管理员刷新
 
```

8.单独设置过期时间

```php
use WebmanAuth\facade\Auth;
use app\model\User;
$user = User::first();
Auth::accessTime(3600)->refreshTime(360000)->login($user);
Auth::accessTime(3600)->refreshTime(360000)->attempt(['name'=> 'tycoonSong','password' => '123456']);
Auth::accessTime(3600)->refresh();

```

9.获取报错信息 Auth::fail();

```
    // 默认不抛异常，异常时返回 null
    $user = Auth::user(); // 适用于“可登录可不登录”的场景
    // 需要强制登录的场景可开启异常模式
    $user = Auth::fail()->user(); // 异常处理参考：https://www.workerman.net/doc/webman/exception.html
```

- 开启redis后,建议开启

```
    // 启用 Redis 后可限制终端会话数量
    // 例如：同账号 web 最多 3 端，ios 最多 1 端
    // client_type 默认为 web，可通过 client_type=ios 指定
    // 在 config/plugin/codex13/auth/app.php 设置：
    'guard' => [
         'user' => [ // 普通用户
             'key' => 'id', // 主键字段
             'field' => ['id','username','email','mobile','avatar'], // 写入 token extend 的字段白名单
             'num' => 0, // -1 不限制；0 单端在线；>0 同一 client_type 最大在线终端数
             'model'=> app\model\User::class // 用户模型
         ]
     ]
     'jwt' => [
         'redis' => false,
         ....
      ]
     
    Auth::logout(true); // 退出该用户全部终端
    
```
- 获取所有redis用户及终端状态
```
    // 可通过 Redis Hash 做终端在线管理（下线、查询有效期等）
    // 该部分按业务自行扩展，本组件不做二次封装
    $guard = 'user';
    Redis::hGetAll('token_'.$guard);
    // 用户 ID=1 的 token 下线（支持批量）
    Redis::hDel('token_'.$guard,[1]);
```

- 直接调用jwt

```
    use WebmanAuth\Facade\JWT as JwtFace;
    JwtFace::guard('user')->make($extend,$access_exp,$refresh_exp); // 生成令牌，也可 make($extend)
    JwtFace::guard('user')->refresh($accessTime = 0); // 刷新令牌，也可 refresh()
    JwtFace::guard('user')->verify($token); // $token 可省略，省略时自动读取当前请求令牌
    JwtFace::guard('user')->getTokenExtend($token) // $token 可省略，省略时自动读取当前请求令牌
```
