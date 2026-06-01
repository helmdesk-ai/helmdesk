<?php

namespace App\Data\MailSetting;

use App\Settings\MailSettings;
use Spatie\LaravelData\Data;

/**
 * 系统邮件服务器设置。
 * 由后端读取后传给 resources/js/pages/admin/systemSettings/MailSetting.vue。
 */
class MailSettingData extends Data
{
    public function __construct(
        public bool $enabled,
        public string $driver,
        public ?string $from_address,
        public ?string $from_name,
        public ?string $smtp_host,
        public ?int $smtp_port,
        public string $smtp_encryption,
        public ?string $smtp_username,
        public ?string $smtp_local_domain,
        public ?int $smtp_timeout,
        public ?string $mailgun_domain,
        public ?string $mailgun_endpoint,
        public string $mailgun_scheme,
        public ?string $postmark_message_stream_id,
        public ?string $ses_region,
        public ?string $sendmail_path,
        public MailExistingSecretsData $existing_secrets,
    ) {}

    public static function fromSettings(MailSettings $settings): self
    {
        return new self(
            enabled: $settings->enabled,
            driver: $settings->driver,
            from_address: $settings->from_address,
            from_name: $settings->from_name,
            smtp_host: $settings->smtp_host,
            smtp_port: $settings->smtp_port,
            smtp_encryption: $settings->smtp_encryption,
            smtp_username: $settings->smtp_username,
            smtp_local_domain: $settings->smtp_local_domain,
            smtp_timeout: $settings->smtp_timeout,
            mailgun_domain: $settings->mailgun_domain,
            mailgun_endpoint: $settings->mailgun_endpoint,
            mailgun_scheme: $settings->mailgun_scheme,
            postmark_message_stream_id: $settings->postmark_message_stream_id,
            ses_region: $settings->ses_region,
            sendmail_path: $settings->sendmail_path,
            existing_secrets: new MailExistingSecretsData(
                smtp_password: filled($settings->smtp_password),
                mailgun_secret: filled($settings->mailgun_secret),
                postmark_token: filled($settings->postmark_token),
                resend_key: filled($settings->resend_key),
                ses_key: filled($settings->ses_key),
                ses_secret: filled($settings->ses_secret),
                ses_token: filled($settings->ses_token),
            ),
        );
    }
}
