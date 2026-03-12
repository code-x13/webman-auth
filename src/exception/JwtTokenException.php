<?php

namespace WebmanAuth\exception;


class JwtTokenException extends \RuntimeException
{
    /**
     * 原始错误信息（字符串或数组）
     * @var array|string
     */
    protected string|array $error;

    public function __construct($error,$code = 401)
    {
        parent::__construct();
        $this->error = $error;
        $this->code = $code;
        $this->message = is_array($error) ? implode(PHP_EOL, $error) : $error;
    }

    /**
     * 设置异常状态码
     * @param int $code
     * @return JwtTokenException
     */
    public function setCode(int $code): JwtTokenException
    {
        $this->code = $code;
        return $this;
    }

    /**
     * 获取验证错误信息
     * @access public
     * @return array|string
     */
    public function getError(): array|string
    {
        return $this->error;
    }
}
