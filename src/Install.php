<?php

namespace WebmanAuth;

class Install
{

    /**
     * 插件安装/卸载时的目录映射关系
     * @var array<string, string>
     */
    protected static array $pathRelation = array (
        'config/plugin/codex13/auth' => 'config/plugin/codex13/auth',
    );

    /**
     * 安装插件文件
     */
    public static function install(): void
    {
        static::installByRelation();
    }

    /**
     * 卸载插件文件
     */
    public static function uninstall(): void
    {
        self::uninstallByRelation();
    }

    /**
     * 按映射关系复制文件到宿主项目
     */
    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = base_path().'/'.substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0777, true);
                }
            }
            copy_dir(__DIR__ . "/$source", base_path()."/$dest");
        }
    }

    /**
     * 按映射关系删除已安装文件
     */
    public static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $dest) {
            $path = base_path()."/$dest";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            remove_dir($path);
        }
    }
    
}
