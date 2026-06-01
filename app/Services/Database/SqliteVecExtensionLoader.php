<?php

namespace App\Services\Database;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 给 sqlite_rag 连接确保 sqlite-vec 扩展可用。
 *
 * 项目实际部署里有两种进程拓扑共存：
 *  1. 生产 / 开发：始终通过 Go 二进制启动（FrankenPHP 嵌入式 PHP）。
 *     Go 在 main 入口调用 sqlite3_auto_extension(sqlite3_vec_init)，把扩展注册到与
 *     PHP 共享的 libsqlite3 上，之后任意 PDO 打开的 sqlite 连接都会自动具备 vec_*
 *     函数，PHP 端无需也不应该再调用 PDO::loadExtension —— 部分构建版本下重复加载
 *     会直接抛 PDOException。
 *  2. 独立的 PHP CLI（CI 的 `php artisan test`、`php artisan tinker` 等）：进程不
 *     经过 Go，sqlite3_auto_extension 没有被注册，PHP 自己得 loadExtension 才能
 *     用 vec0 虚表。
 *
 * 为了在两种拓扑下都正确，这里不再按 PHP_SAPI 判断，改为 **先用 SELECT vec_version()
 * 探测**：命中说明扩展已被进程级 auto extension 加载，跳过即可；未命中再退化到
 * PDO::loadExtension。
 */
class SqliteVecExtensionLoader
{
    private const CONNECTION_NAME = 'sqlite_rag';

    /**
     * 已经验证过的 PDO 对象集合，避免每次 ConnectionEstablished 都重复探测。
     *
     * @var array<int, true>
     */
    private array $verifiedPdoIds = [];

    /**
     * 确保给定连接的 vec 扩展已经加载；非 sqlite_rag 连接直接跳过。
     */
    public function ensureLoadedFor(Connection $connection): void
    {
        if ($connection->getName() !== self::CONNECTION_NAME) {
            return;
        }

        $pdo = $connection->getPdo();
        $pdoId = spl_object_id($pdo);
        if (isset($this->verifiedPdoIds[$pdoId])) {
            return;
        }

        if ($this->extensionAvailable($pdo)) {
            $this->verifiedPdoIds[$pdoId] = true;

            return;
        }

        $pdo->loadExtension($this->resolveExtensionPath());
        $this->verifiedPdoIds[$pdoId] = true;
    }

    /**
     * 通过执行 vec_version() 探测扩展是否已经在当前 PDO 上可用。
     * Go 端 sqlite3_auto_extension 注册成功时这里会拿到版本号。
     */
    private function extensionAvailable(PDO $pdo): bool
    {
        try {
            $version = $pdo->query('SELECT vec_version()')?->fetchColumn();

            return is_string($version) && $version !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 从 config/sqlite_vec.php 读取扩展库路径，并按校验和验证文件完整性。
     */
    private function resolveExtensionPath(): string
    {
        $configuredPath = config('sqlite_vec.path');
        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            throw new RuntimeException('sqlite-vec library path is not configured for the current platform.');
        }

        $resolvedPath = realpath($configuredPath) ?: $configuredPath;
        if (! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException(sprintf(
                'sqlite-vec library was not found or is not readable at [%s].',
                $resolvedPath
            ));
        }

        $expectedChecksum = trim((string) config('sqlite_vec.sha256', ''));
        if ($expectedChecksum !== '') {
            $actualChecksum = hash_file('sha256', $resolvedPath);
            if (! is_string($actualChecksum) || ! hash_equals(strtolower($expectedChecksum), strtolower($actualChecksum))) {
                throw new RuntimeException(sprintf(
                    'sqlite-vec checksum mismatch for [%s].',
                    $resolvedPath
                ));
            }
        }

        return $resolvedPath;
    }
}
