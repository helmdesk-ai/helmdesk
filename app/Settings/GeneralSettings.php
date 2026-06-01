<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * 系统基础信息设置。
 */
class GeneralSettings extends Settings
{
    /**
     * 新安装时的主机地址占位值，首次超级管理员访问后会替换为实际域名。
     */
    public const DEFAULT_BASE_URL = 'http://localhost';

    /**
     * 系统对外访问地址；用于生成邮件、回调和前端分享链接。
     */
    public ?string $base_url;

    /**
     * 系统名称；显示在后台标题、导航和浏览器标题里。
     */
    public ?string $name;

    /**
     * 系统 Logo 附件 ID；前端读取后展示在后台品牌区域。
     */
    public ?string $logo_id;

    /**
     * 版权信息；用于登录页或系统页脚展示。
     */
    public ?string $copyright;

    /**
     * ICP备案信息；需要备案展示时显示在页脚。
     */
    public ?string $icp_record;

    /**
     * 系统版本号；用于后台关于信息或运维排查。
     */
    public ?string $version;

    /**
     * 是否允许访客在登录页自主注册账号。
     */
    public bool $allow_registration = true;

    /**
     * 返回系统基础设置所属的 settings 分组。
     */
    public static function group(): string
    {
        return 'general';
    }
}
