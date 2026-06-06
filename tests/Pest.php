<?php

use App\Models\SystemContext;
use App\Models\User;
use Tests\TestCase;

/*
 * 确保 sqlite_rag 测试库在每个测试进程开始时为空。
 * phpunit.xml 把 DB_RAG_DATABASE 指向 storage/framework/testing/rag.sqlite，
 * SQLite 连接要求文件已存在，Laravel 迁移会负责创建表结构。
 */
$ragDbPath = __DIR__.'/../storage/framework/testing/rag.sqlite';
$ragDbDir = dirname($ragDbPath);
if (! is_dir($ragDbDir)) {
    mkdir($ragDbDir, 0755, true);
}
if (file_exists($ragDbPath)) {
    unlink($ragDbPath);
}
touch($ragDbPath);

require_once __DIR__.'/Support/KnowledgeRecallHelpers.php';

pest()->extend(TestCase::class)
    ->in('Feature');

/**
 * @param  array<string, mixed>  $userAttributes
 * @param  array<string, mixed>  $systemAttributes
 * @return array{0: SystemContext, 1: User}
 */
function createSystemWithOwner(array $userAttributes = [], array $systemAttributes = []): array
{
    $user = User::factory()->create(array_merge([
        'is_super_admin' => true,
    ], $userAttributes));

    $systemContext = SystemContext::factory()->create(array_merge([
        'owner_id' => $user->id,
    ], $systemAttributes));

    return [$systemContext, $user];
}

function createSuperAdmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'is_super_admin' => true,
    ], $attributes));
}
