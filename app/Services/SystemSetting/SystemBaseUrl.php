<?php

namespace App\Services\SystemSetting;

use App\Settings\GeneralSettings;

/**
 * 系统对外主机地址（base_url）的唯一读取入口。
 *
 * 始终从系统设置 GeneralSettings 读取——该 settings 类是 scoped 绑定，每个请求重新从库加载，
 * 因此每次读取都是最新值。供 webhook 地址、组件安装代码、邮件链接、CORS 校验等所有「对外地址」
 * 消费方统一调用，替代散落各处、口径不一的 config('app.url') 读取。
 * base_url 仍是未配置时的占位默认值（DEFAULT_BASE_URL）或为空时，回落到环境推断出的 config('app.url')。
 */
class SystemBaseUrl
{
    /**
     * 返回当前生效的对外主机地址（已去除尾部斜杠）。
     */
    public function value(): string
    {
        $configured = rtrim(trim((string) app(GeneralSettings::class)->base_url), '/');

        if ($configured === '' || $configured === GeneralSettings::DEFAULT_BASE_URL) {
            return rtrim((string) config('app.url'), '/');
        }

        return $configured;
    }
}
