<?php

namespace App\Settings;

use App\Enums\MailDriver;
use Spatie\LaravelSettings\Settings;

/**
 * 系统邮件服务器配置。
 */
class MailSettings extends Settings
{
    /**
     * 是否启用后台保存的系统邮件配置。
     */
    public bool $enabled = false;

    /**
     * 邮件发送驱动，来自邮箱服务器设置页。
     */
    public string $driver = MailDriver::Smtp->value;

    /**
     * 系统事务邮件默认发件邮箱。
     */
    public ?string $from_address = null;

    /**
     * 系统事务邮件默认发件人名称。
     */
    public ?string $from_name = null;

    /**
     * SMTP 服务器主机名。
     */
    public ?string $smtp_host = null;

    /**
     * SMTP 服务器端口。
     */
    public ?int $smtp_port = 587;

    /**
     * SMTP 连接加密方式。
     */
    public string $smtp_encryption = 'starttls';

    /**
     * SMTP 认证用户名。
     */
    public ?string $smtp_username = null;

    /**
     * SMTP 认证密码，保存时加密。
     */
    public ?string $smtp_password = null;

    /**
     * SMTP EHLO/HELO 使用的本地域名。
     */
    public ?string $smtp_local_domain = null;

    /**
     * SMTP 连接超时时间，单位为秒。
     */
    public ?int $smtp_timeout = 10;

    /**
     * Mailgun 发送域名。
     */
    public ?string $mailgun_domain = null;

    /**
     * Mailgun API Secret，保存时加密。
     */
    public ?string $mailgun_secret = null;

    /**
     * Mailgun API Endpoint。
     */
    public ?string $mailgun_endpoint = 'api.mailgun.net';

    /**
     * Mailgun API 请求协议。
     */
    public string $mailgun_scheme = 'https';

    /**
     * Postmark Server Token，保存时加密。
     */
    public ?string $postmark_token = null;

    /**
     * Postmark Message Stream 标识。
     */
    public ?string $postmark_message_stream_id = null;

    /**
     * Resend API Key，保存时加密。
     */
    public ?string $resend_key = null;

    /**
     * Amazon SES Access Key ID，保存时加密。
     */
    public ?string $ses_key = null;

    /**
     * Amazon SES Secret Access Key，保存时加密。
     */
    public ?string $ses_secret = null;

    /**
     * Amazon SES 区域。
     */
    public ?string $ses_region = 'us-east-1';

    /**
     * Amazon SES Session Token，保存时加密。
     */
    public ?string $ses_token = null;

    /**
     * Sendmail 可执行命令路径。
     */
    public ?string $sendmail_path = '/usr/sbin/sendmail -bs -i';

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'smtp_password',
            'mailgun_secret',
            'postmark_token',
            'resend_key',
            'ses_key',
            'ses_secret',
            'ses_token',
        ];
    }

    public static function group(): string
    {
        return 'mail';
    }
}
