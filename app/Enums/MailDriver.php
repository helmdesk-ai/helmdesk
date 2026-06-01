<?php

namespace App\Enums;

use App\Contracts\LabeledEnum;

/**
 * 系统邮件发送驱动。
 */
enum MailDriver: string implements LabeledEnum
{
    case Smtp = 'smtp';
    case Sendmail = 'sendmail';
    case Mailgun = 'mailgun';
    case Postmark = 'postmark';
    case Resend = 'resend';
    case Ses = 'ses-v2';

    public function label(): string
    {
        return match ($this) {
            self::Smtp => __('mail_settings.drivers.smtp'),
            self::Sendmail => __('mail_settings.drivers.sendmail'),
            self::Mailgun => __('mail_settings.drivers.mailgun'),
            self::Postmark => __('mail_settings.drivers.postmark'),
            self::Resend => __('mail_settings.drivers.resend'),
            self::Ses => __('mail_settings.drivers.ses'),
        };
    }
}
