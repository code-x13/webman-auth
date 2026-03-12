<?php

namespace WebmanAuth;

class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * 插件安装/卸载时的目录映射关系
     * @var array<string, string>
     */
    protected static array $pathRelation = array (
        'config/plugin/codex13/auth' => 'config/plugin/codex13/auth',
    );

    /**
     * 安装方法
     *
     * 执行插件安装操作
     *
     * @return void
     */
    public static function install(): void
    {
        static::installByRelation();
    }

    /**
     * 卸载方法
     *
     * 执行插件卸载操作
     *
     * @return void
     */
    public static function uninstall(): void
    {
        self::uninstallByRelation();
    }

    /**
     * 根据路径关系执行安装
     *
     * 遍历路径映射关系，创建目录并复制文件
     *
     * @return void
     */
    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0777, true);
                }
            }

            copy_dir(__DIR__ . "/$source", base_path() . "/$dest", true);
            echo "Create $dest
";
        }
    }

    /**
     * 根据路径关系执行卸载
     *
     * 遍历路径映射关系，删除对应的文件和目录
     *
     * @return void
     */
    public static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $dest) {
            $path = base_path() . "/$dest";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            echo "Remove $dest
";
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            remove_dir($path);
        }
    }
    
}
