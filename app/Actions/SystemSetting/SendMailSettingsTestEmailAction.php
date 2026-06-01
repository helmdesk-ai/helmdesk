<?php

namespace App\Actions\SystemSetting;

use App\Data\MailSetting\FormSendMailSettingsTestEmailData;
use App\Enums\MailDriver;
use App\Notifications\MailSettingsTestNotification;
use App\Services\Mail\ApplyMailSettings;
use App\Settings\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * 使用已保存的邮箱服务器配置发送一封测试邮件。
 */
class SendMailSettingsTestEmailAction
{
    use AsAction;

    public function __construct(
        private readonly MailSettings $settings,
        private readonly ApplyMailSettings $applyMailSettings,
    ) {}

    /**
     * 校验当前邮件配置并发送测试邮件。
     */
    public function handle(FormSendMailSettingsTestEmailData $data): void
    {
        $this->validateSettingsAreSendable();
        $this->applyMailSettings->apply();

        try {
            Notification::route('mail', $data->email)->notify(new MailSettingsTestNotification);
        } catch (Throwable $exception) {
            Log::warning('Mail settings test email failed.', [
                'driver' => $this->settings->driver,
                'recipient' => $data->email,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => __('mail_settings.validation.test_send_failed'),
            ]);
        }
    }

    /**
     * 处理测试邮件发送请求。
     */
    public function asController(Request $request): RedirectResponse
    {
        $this->handle(FormSendMailSettingsTestEmailData::from($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('mail_settings.test.sent'),
        ]);

        return back();
    }

    /**
     * 校验已保存的配置是否足以发送邮件。
     */
    private function validateSettingsAreSendable(): void
    {
        if (! $this->settings->enabled) {
            $this->fail('email', __('mail_settings.validation.test_requires_enabled'));
        }

        if (! filled($this->settings->from_address)) {
            $this->fail('email', __('mail_settings.validation.test_requires_from_address'));
        }

        match (MailDriver::from($this->settings->driver)) {
            MailDriver::Smtp => $this->validateSmtp(),
            MailDriver::Sendmail => $this->requireFilled($this->settings->sendmail_path),
            MailDriver::Mailgun => $this->validateMailgun(),
            MailDriver::Postmark => $this->requireFilled($this->settings->postmark_token),
            MailDriver::Resend => $this->requireFilled($this->settings->resend_key),
            MailDriver::Ses => $this->validateSes(),
        };
    }

    /**
     * 校验 SMTP 必填配置。
     */
    private function validateSmtp(): void
    {
        $this->requireFilled($this->settings->smtp_host);
        $this->requireFilled($this->settings->smtp_port);
    }

    /**
     * 校验 Mailgun 必填配置。
     */
    private function validateMailgun(): void
    {
        $this->requireFilled($this->settings->mailgun_domain);
        $this->requireFilled($this->settings->mailgun_secret);
    }

    /**
     * 校验 SES 必填配置。
     */
    private function validateSes(): void
    {
        $this->requireFilled($this->settings->ses_key);
        $this->requireFilled($this->settings->ses_secret);
        $this->requireFilled($this->settings->ses_region);
    }

    /**
     * 要求指定配置值不为空。
     */
    private function requireFilled(mixed $value): void
    {
        if (! filled($value)) {
            $this->fail('email', __('mail_settings.validation.test_requires_complete_settings'));
        }
    }

    /**
     * 抛出指定字段的校验错误。
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }
}
