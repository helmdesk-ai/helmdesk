<?php

namespace App\Data\MailSetting;

use Spatie\LaravelData\Data;

/**
 * 邮箱服务器测试邮件表单。
 */
class FormSendMailSettingsTestEmailData extends Data
{
    public function __construct(
        public string $email,
    ) {}

    public static function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
