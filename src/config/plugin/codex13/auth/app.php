<?php
 return [
     'enable' => true,
     'guard' => [
         'user' => [
             'key' => 'id',
             'field' => ['id','name','email','mobile'], // 写入 token extend 的字段白名单
             'num' => 0, // -1 不限制；0 单端在线；>0 同一 client_type 最大在线终端数
             'model'=> env('AUTH_USER_MODEL',''),
         ],
         'admin' => [
             'key' => 'id',
             'field' => ['id','name','email','mobile'], // 写入 token extend 的字段白名单
             'num' => 0, // -1 不限制；0 单端在线；>0 同一 client_type 最大在线终端数
             'model'=> env('AUTH_ADMIN_MODEL',''),
         ]
     ],
     'jwt' => [
         'redis' => env('AUTH_JWT_REDIS', false),
         // 签名算法：ES256、HS256、HS384、HS512、RS256、RS384、RS512
         'algorithms' => 'HS256',
         // bcrypt cost，范围 4-31
         'bcrypt_cost' => env('AUTH_BCRYPT_COST', 10),
         // access token 密钥（HS* 算法）
         // ⚠️ 安全提示：生产环境必须使用 `php webman auth:key` 生成新密钥
         'access_secret_key' => 'w5LgNx5luRRjmamZFSqz3cPHOp9KuQPExlvgi18DN4SdnSI9obcVEhiZVE0NIIC7',
         // access token 过期时间（秒）
         'access_exp' => 36000,
         // refresh token 密钥（HS* 算法）
         // ⚠️ 安全提示：生产环境必须使用 `php webman auth:key` 生成新密钥
         'refresh_secret_key' => 'w5LgNx5luRRjmamZFSqz3cPHOp9KuQPExlvgi18DN4SdnSI9obcVEhiZVE0NIIC7',
         // refresh token 过期时间（秒）
         'refresh_exp' => 72000,
         // token 签发者
         'iss' => 'webman',
         // token 签发时间（会在 payload 中按当前时间动态写入）
         'iat' => time(),

         /**
          * access令牌 RS256 私钥
          * 生成RSA私钥(Linux系统)：openssl genrsa -out access_private_key.key 1024 (2048)
          */
         'access_private_key' => <<<EOD
-----BEGIN RSA PRIVATE KEY-----
...
-----END RSA PRIVATE KEY-----
EOD,
         /**
          * access令牌 RS256 公钥
          * 生成RSA公钥(Linux系统)：openssl rsa -in access_private_key.key -pubout -out access_public_key.key
          */
         'access_public_key' => <<<EOD
-----BEGIN PUBLIC KEY-----
...
-----END PUBLIC KEY-----
EOD,

         /**
          * refresh令牌 RS256 私钥
          * 生成RSA私钥(Linux系统)：openssl genrsa -out refresh_private_key.key 1024 (2048)
          */
         'refresh_private_key' => <<<EOD
-----BEGIN RSA PRIVATE KEY-----
...
-----END RSA PRIVATE KEY-----
EOD,
         /**
          * refresh令牌 RS256 公钥
          * 生成RSA公钥(Linux系统)：openssl rsa -in refresh_private_key.key -pubout -out refresh_public_key.key
          */
         'refresh_public_key' => <<<EOD
-----BEGIN PUBLIC KEY-----
...
-----END PUBLIC KEY-----
EOD,
     ],
     // 缓存 key 前缀
     'cache_key' => 'webman_auth_token_',
 ];
