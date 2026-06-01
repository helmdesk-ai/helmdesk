<?php

namespace App\Services\Mail;

use App\Enums\MailDriver;
use App\Services\SystemSetting\SystemBaseUrl;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Illuminate\Mail\MailManager;

/**
 * 将后台保存的邮件服务器配置应用到 Laravel Mail 运行时。
 */
class ApplyMailSettings
{
    /**
     * @var array{default: mixed, from: mixed, mailers: mixed, services: array<string, mixed>}|null
     */
    private static ?array $baseConfig = null;

    public function __construct(
        private readonly MailSettings $mailSettings,
        private readonly GeneralSettings $generalSettings,
        private readonly SystemBaseUrl $systemBaseUrl,
    ) {}

    /**
     * 将后台保存的邮件配置应用到当前运行时。
     *
     * 认证邮件（验证/重置链接）在队列里生成，UrlGenerator 取 config('app.url') 作为根地址，
     * 故 $applyBaseUrl 时把系统设置的 base_url 同步进 config('app.url')，保证链接指向真实对外域名。
     */
    public function apply(bool $applyBaseUrl = false): void
    {
        $this->captureBaseConfig();
        $this->mailSettings->refresh();
        $this->generalSettings->refresh();

        if ($applyBaseUrl) {
            config(['app.url' => $this->systemBaseUrl->value()]);
        }

        if (! $this->mailSettings->enabled) {
            $this->restoreBaseMailConfig();
            $this->forgetResolvedMailers();

            return;
        }

        $driver = MailDriver::from($this->mailSettings->driver);

        config([
            'mail.default' => $driver->value,
            'mail.from.address' => $this->mailSettings->from_address,
            'mail.from.name' => $this->mailSettings->from_name ?: $this->generalSettings->name,
        ]);

        match ($driver) {
            MailDriver::Smtp => $this->applySmtp(),
            MailDriver::Sendmail => $this->applySendmail(),
            MailDriver::Mailgun => $this->applyMailgun(),
            MailDriver::Postmark => $this->applyPostmark(),
            MailDriver::Resend => $this->applyResend(),
            MailDriver::Ses => $this->applySes(),
        };

        $this->forgetResolvedMailers();
    }

    /**
     * 记录 Laravel 原始邮件配置，便于关闭后台邮件时恢复。
     */
    private function captureBaseConfig(): void
    {
        if (self::$baseConfig !== null) {
            return;
        }

        self::$baseConfig = [
            'default' => config('mail.default'),
            'from' => config('mail.from'),
            'mailers' => config('mail.mailers'),
            'services' => [
                'mailgun' => config('services.mailgun'),
                'postmark' => config('services.postmark'),
                'resend' => config('services.resend'),
                'ses' => config('services.ses'),
            ],
        ];
    }

    /**
     * 恢复 Laravel 原始邮件配置。
     */
    private function restoreBaseMailConfig(): void
    {
        if (self::$baseConfig === null) {
            return;
        }

        config([
            'mail.default' => self::$baseConfig['default'],
            'mail.from' => self::$baseConfig['from'],
            'mail.mailers' => self::$baseConfig['mailers'],
            'services.mailgun' => self::$baseConfig['services']['mailgun'],
            'services.postmark' => self::$baseConfig['services']['postmark'],
            'services.resend' => self::$baseConfig['services']['resend'],
            'services.ses' => self::$baseConfig['services']['ses'],
        ]);
    }

    /**
     * 清理已解析的 mailer，使新配置立即生效。
     */
    private function forgetResolvedMailers(): void
    {
        $manager = app('mail.manager');

        if ($manager instanceof MailManager) {
            $manager->forgetMailers();
        }
    }

    /**
     * 应用 SMTP 驱动配置。
     */
    private function applySmtp(): void
    {
        config(['mail.mailers.smtp' => [
            'transport' => 'smtp',
            'scheme' => match ($this->mailSettings->smtp_encryption) {
                'ssl' => 'smtps',
                default => null,
            },
            'host' => $this->mailSettings->smtp_host,
            'port' => $this->mailSettings->smtp_port,
            'username' => $this->mailSettings->smtp_username,
            'password' => $this->mailSettings->smtp_password,
            'timeout' => $this->mailSettings->smtp_timeout,
            'local_domain' => $this->mailSettings->smtp_local_domain
                ?: parse_url($this->systemBaseUrl->value(), PHP_URL_HOST),
        ]]);
    }

    /**
     * 应用 Sendmail 驱动配置。
     */
    private function applySendmail(): void
    {
        config(['mail.mailers.sendmail' => [
            'transport' => 'sendmail',
            'path' => $this->mailSettings->sendmail_path,
        ]]);
    }

    /**
     * 应用 Mailgun 驱动配置。
     */
    private function applyMailgun(): void
    {
        config([
            'mail.mailers.mailgun' => ['transport' => 'mailgun'],
            'services.mailgun.domain' => $this->mailSettings->mailgun_domain,
            'services.mailgun.secret' => $this->mailSettings->mailgun_secret,
            'services.mailgun.endpoint' => $this->mailSettings->mailgun_endpoint,
            'services.mailgun.scheme' => $this->mailSettings->mailgun_scheme,
        ]);
    }

    /**
     * 应用 Postmark 驱动配置。
     */
    private function applyPostmark(): void
    {
        config([
            'mail.mailers.postmark' => [
                'transport' => 'postmark',
                'message_stream_id' => $this->mailSettings->postmark_message_stream_id,
            ],
            'services.postmark.key' => $this->mailSettings->postmark_token,
        ]);
    }

    /**
     * 应用 Resend 驱动配置。
     */
    private function applyResend(): void
    {
        config([
            'mail.mailers.resend' => ['transport' => 'resend'],
            'services.resend.key' => $this->mailSettings->resend_key,
        ]);
    }

    /**
     * 应用 SES 驱动配置。
     */
    private function applySes(): void
    {
        config([
            'mail.mailers.ses-v2' => ['transport' => 'ses-v2'],
            'services.ses.key' => $this->mailSettings->ses_key,
            'services.ses.secret' => $this->mailSettings->ses_secret,
            'services.ses.region' => $this->mailSettings->ses_region,
            'services.ses.token' => $this->mailSettings->ses_token,
        ]);
    }
}
