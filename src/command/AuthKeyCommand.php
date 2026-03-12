<?php

namespace WebmanAuth\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use WebmanAuth\facade\Str;


class AuthKeyCommand extends Command
{
    protected static string $defaultName = 'auth:key';
    protected static string $defaultDescription = 'Generate auth jwt secret keys';

    /**
     * 注册命令参数
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name description');
    }

    /**
     * 生成 access/refresh 密钥并写入配置文件 config/plugin/codex13/auth/app.php
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln('Generate jwtKey Start');
        // 按你的选择：access / refresh 使用不同随机密钥
        $accessKey  = Str::random(64);
        $refreshKey = Str::random(64);

        $configPath = base_path() . '/config/plugin/codex13/auth/app.php';
        $content = file_get_contents($configPath);

        // 替换 access_secret_key
        $oldAccess = "'access_secret_key' => '" . config('plugin.codex13.auth.app.jwt.access_secret_key') . "'";
        $newAccess = "'access_secret_key' => '" . $accessKey . "'";
        $content = str_replace($oldAccess, $newAccess, $content);

        // 替换 refresh_secret_key
        $oldRefresh = "'refresh_secret_key' => '" . config('plugin.codex13.auth.app.jwt.refresh_secret_key') . "'";
        $newRefresh = "'refresh_secret_key' => '" . $refreshKey . "'";
        $content = str_replace($oldRefresh, $newRefresh, $content);

        file_put_contents($configPath, $content);

        $output->writeln('Generate jwtKey End');
        $output->writeln('New access_secret_key and refresh_secret_key have been written to config/plugin/codex13/auth/app.php');

        return self::SUCCESS;
    }

}
