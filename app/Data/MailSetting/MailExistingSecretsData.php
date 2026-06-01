<?php

namespace App\Data\MailSetting;

use Spatie\LaravelData\Data;

/**
 * 邮箱服务器设置页的已保存密钥状态。
 * 只向前端暴露是否存在，不返回任何实际密钥内容。
 */
class MailExistingSecretsData extends Data
{
    public function __construct(
        public bool $smtp_password,
        public bool $mailgun_secret,
        public bool $postmark_token,
        public bool $resend_key,
        public bool $ses_key,
        public bool $ses_secret,
        public bool $ses_token,
    ) {}
}
