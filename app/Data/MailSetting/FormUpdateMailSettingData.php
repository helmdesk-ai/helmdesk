<?php

namespace App\Data\MailSetting;

use App\Enums\MailDriver;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 来自邮箱服务器设置页的保存表单，用于校验并更新系统邮件发送配置。
 */
class FormUpdateMailSettingData extends Data
{
    public function __construct(
        public bool $enabled,
        public string $driver,
        public ?string $from_address = null,
        public ?string $from_name = null,
        public ?string $smtp_host = null,
        public ?int $smtp_port = null,
        public ?string $smtp_encryption = null,
        public ?string $smtp_username = null,
        public ?string $smtp_password = null,
        public bool $clear_smtp_password = false,
        public ?string $smtp_local_domain = null,
        public ?int $smtp_timeout = null,
        public ?string $mailgun_domain = null,
        public ?string $mailgun_secret = null,
        public bool $clear_mailgun_secret = false,
        public ?string $mailgun_endpoint = null,
        public ?string $mailgun_scheme = null,
        public ?string $postmark_token = null,
        public bool $clear_postmark_token = false,
        public ?string $postmark_message_stream_id = null,
        public ?string $resend_key = null,
        public bool $clear_resend_key = false,
        public ?string $ses_key = null,
        public bool $clear_ses_key = false,
        public ?string $ses_secret = null,
        public bool $clear_ses_secret = false,
        public ?string $ses_region = null,
        public ?string $ses_token = null,
        public bool $clear_ses_token = false,
        public ?string $sendmail_path = null,
    ) {}

    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'driver' => ['required', 'string', Rule::enum(MailDriver::class)],
            'from_address' => ['nullable', 'string', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', 'string', Rule::in(['none', 'starttls', 'ssl'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'clear_smtp_password' => ['nullable', 'boolean'],
            'smtp_local_domain' => ['nullable', 'string', 'max:255'],
            'smtp_timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
            'mailgun_domain' => ['nullable', 'string', 'max:255'],
            'mailgun_secret' => ['nullable', 'string', 'max:1024'],
            'clear_mailgun_secret' => ['nullable', 'boolean'],
            'mailgun_endpoint' => ['nullable', 'string', 'max:255'],
            'mailgun_scheme' => ['nullable', 'string', Rule::in(['https', 'http'])],
            'postmark_token' => ['nullable', 'string', 'max:1024'],
            'clear_postmark_token' => ['nullable', 'boolean'],
            'postmark_message_stream_id' => ['nullable', 'string', 'max:255'],
            'resend_key' => ['nullable', 'string', 'max:1024'],
            'clear_resend_key' => ['nullable', 'boolean'],
            'ses_key' => ['nullable', 'string', 'max:1024'],
            'clear_ses_key' => ['nullable', 'boolean'],
            'ses_secret' => ['nullable', 'string', 'max:1024'],
            'clear_ses_secret' => ['nullable', 'boolean'],
            'ses_region' => ['nullable', 'string', 'max:64'],
            'ses_token' => ['nullable', 'string', 'max:2048'],
            'clear_ses_token' => ['nullable', 'boolean'],
            'sendmail_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
