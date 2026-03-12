<?php

declare(strict_types=1);

namespace WebmanAuth\facade;

use BadMethodCallException;
use Exception;
use Symfony\Component\String\UnicodeString;

/**
 * 字符串工具类（基于 Symfony String）
 * 协程安全：无静态缓存，完全委托给 Symfony Component
 */
class Str
{
    /**
     * 检查字符串是否以指定前缀开始
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    /**
     * 检查字符串是否以指定后缀结束
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    /**
     * 检查字符串是否包含子字符串
     * @param string $haystack
     * @param string|array $needles
     * @return bool
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 字符串转 snake_case
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1'.$delimiter, $value);
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * 字符串转 camelCase
     */
    public static function camel(string $value): string
    {
        return lcfirst(static::studly($value));
    }

    /**
     * 字符串转 StudlyCase
     */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /**
     * 字符串转 kebab-case
     */
    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    /**
     * 字符串转小写
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * 字符串转大写
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * 字符串转标题大小写
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * 生成随机字符串
     * @throws Exception
     */
    public static function random(int $length = 16): string
    {
        return substr(bin2hex(random_bytes($length / 2 + 1)), 0, $length);
    }

    /**
     * 截取字符串
     */
    public static function substr(string $string, int $start, ?int $length = null): string
    {
        return mb_substr($string, $start, $length, 'UTF-8');
    }

    /**
     * 获取字符串长度
     */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * 限制字符串长度
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_strimwidth($value, 0, $limit - mb_strlen($end, 'UTF-8'), '', 'UTF-8')) . $end;
    }

    /**
     * 替换字符串中第一次出现的给定值
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }
        $position = strpos($subject, $search);
        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }
        return $subject;
    }

    /**
     * 替换字符串中最后一次出现的给定值
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $position = strrpos($subject, $search);
        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }
        return $subject;
    }

    /**
     * 字符串转 slug
     */
    public static function slug(string $title, string $separator = '-'): string
    {
        // 转 ASCII
        $title = (new UnicodeString($title))->ascii()->toString();
        // 转小写并替换特殊字符
        $title = strtolower($title);
        // 替换非字母数字字符为分隔符
        $title = preg_replace('/[^a-z0-9]+/', $separator, $title);
        // 移除首尾分隔符
        return trim($title, $separator);
    }

    /**
     * 字符串首字母大写
     */
    public static function ucfirst(string $string): string
    {
        return static::upper(static::substr($string, 0, 1)) . static::substr($string, 1);
    }

    /**
     * 字符串转 ASCII
     */
    public static function ascii(string $value): UnicodeString
    {
        return (new UnicodeString($value))->ascii();
    }

    /**
     * 移除字符串中的空格
     */
    public static function removeSpaces(string $value): string
    {
        return (new UnicodeString($value))->replaceMatches('/\s+/u', '')->toString();
    }

    /**
     * 检查字符串是否匹配模式（支持 * 通配符）
     * @param string|array $pattern
     * @param string $value
     * @return bool
     */
    public static function is(string|array $pattern, string $value): bool
    {
        $patterns = (array) $pattern;
        if (empty($patterns)) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if ($pattern === $value) {
                return true;
            }
            $pattern = str_replace('\*', '.*', preg_quote($pattern, '#'));
            $pattern = str_replace('*', '.*', $pattern);
            if (preg_match('#^' . $pattern . '\z#u', $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析 Class@method
     */
    public static function parseCallback(string $callback, ?string $default = null): array
    {
        return static::contains($callback, '@') ? explode('@', $callback, 2) : [$callback, $default];
    }

    /**
     * 字符串编码转换
     */
    public static function encoding(string $string, string $to = 'utf-8', string $from = 'gb2312'): string
    {
        return mb_convert_encoding($string, $to, $from);
    }

    /**
     * 动态方法调用（转发到 Symfony\Component\String\UnicodeString）
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public static function __callStatic(string $method, array $args)
    {
        if (method_exists(UnicodeString::class, $method)) {
            $str = new UnicodeString($args[0] ?? '');
            return $str->$method(...array_slice($args, 1));
        }
        throw new BadMethodCallException("Method {$method} does not exist.");
    }
}
