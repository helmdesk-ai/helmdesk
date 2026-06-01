<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 邮箱配置测试邮件通知。
 */
class MailSettingsTestNotification extends Notification
{
    use Queueable;

    /**
     * 获取通知发送渠道。
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * 构建测试邮件内容。
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.test.subject'))
            ->line(__('mail.test.line'));
    }
}
