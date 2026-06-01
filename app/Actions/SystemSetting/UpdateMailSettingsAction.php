<?php

namespace App\Actions\SystemSetting;

use App\Data\MailSetting\FormUpdateMailSettingData;
use App\Enums\MailDriver;
use App\Services\Mail\ApplyMailSettings;
use App\Settings\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 更新系统邮箱服务器配置。
 */
class UpdateMailSettingsAction
{
    use AsAction;

    public function __construct(
        public MailSettings $settings,
        private readonly ApplyMailSettings $applyMailSettings,
    ) {}

    /**
     * 保存邮件配置并刷新运行时 Mail 配置。
     */
    public function handle(FormUpdateMailSettingData $data): void
    {
        $driver = MailDriver::from($data->driver);

        $this->validateEnabledConfiguration($data, $driver);

        $this->settings->enabled = $data->enabled;
        $this->settings->driver = $driver->value;
        $this->settings->from_address = $this->blankToNull($data->from_address);
        $this->settings->from_name = $this->blankToNull($data->from_name);
        $this->settings->smtp_host = $this->blankToNull($data->smtp_host);
        $this->settings->smtp_port = $data->smtp_port;
        $this->settings->smtp_encryption = $data->smtp_encryption ?: 'starttls';
        $this->settings->smtp_username = $this->blankToNull($data->smtp_username);
        $this->settings->smtp_local_domain = $this->blankToNull($data->smtp_local_domain);
        $this->settings->smtp_timeout = $data->smtp_timeout;
        $this->settings->mailgun_domain = $this->blankToNull($data->mailgun_domain);
        $this->settings->mailgun_endpoint = $this->blankToNull($data->mailgun_endpoint)
            ?? 'api.mailgun.net';
        $this->settings->mailgun_scheme = $data->mailgun_scheme ?: 'https';
        $this->settings->postmark_message_stream_id = $this->blankToNull($data->postmark_message_stream_id);
        $this->settings->ses_region = $this->blankToNull($data->ses_region);
        $this->settings->sendmail_path = $this->blankToNull($data->sendmail_path);

        $this->updateSecret('smtp_password', $data->smtp_password, $data->clear_smtp_password);
        $this->updateSecret(
            'mailgun_secret',
            $data->mailgun_secret,
            $data->clear_mailgun_secret,
        );
        $this->updateSecret(
            'postmark_token',
            $data->postmark_token,
            $data->clear_postmark_token,
        );
        $this->updateSecret('resend_key', $data->resend_key, $data->clear_resend_key);
        $this->updateSecret('ses_key', $data->ses_key, $data->clear_ses_key);
        $this->updateSecret('ses_secret', $data->ses_secret, $data->clear_ses_secret);
        $this->updateSecret('ses_token', $data->ses_token, $data->clear_ses_token);

        $this->settings->save();
        $this->applyMailSettings->apply();
    }

    /**
     * 处理邮件配置更新请求。
     */
    public function asController(Request $request): RedirectResponse
    {
        $this->handle(FormUpdateMailSettingData::from($request));

        return back();
    }

    /**
     * 校验启用状态下当前驱动需要的完整配置。
     */
    private function validateEnabledConfiguration(FormUpdateMailSettingData $data, MailDriver $driver): void
    {
        if (! $data->enabled) {
            return;
        }

        $errors = [];

        $this->requireFilled($errors, 'from_address', $data->from_address);

        match ($driver) {
            MailDriver::Smtp => $this->validateSmtp($errors, $data),
            MailDriver::Sendmail => $this->requireFilled($errors, 'sendmail_path', $data->sendmail_path),
            MailDriver::Mailgun => $this->validateMailgun($errors, $data),
            MailDriver::Postmark => $this->requireSecret(
                $errors,
                'postmark_token',
                $data->postmark_token,
                $this->currentSecret($this->settings->postmark_token, $data->clear_postmark_token),
            ),
            MailDriver::Resend => $this->requireSecret(
                $errors,
                'resend_key',
                $data->resend_key,
                $this->currentSecret($this->settings->resend_key, $data->clear_resend_key),
            ),
            MailDriver::Ses => $this->validateSes($errors, $data),
        };

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * 校验 SMTP 驱动配置。
     *
     * @param  array<string, string>  $errors
     */
    private function validateSmtp(array &$errors, FormUpdateMailSettingData $data): void
    {
        $this->requireFilled($errors, 'smtp_host', $data->smtp_host);
        $this->requireFilled($errors, 'smtp_port', $data->smtp_port);
    }

    /**
     * 校验 Mailgun 驱动配置。
     *
     * @param  array<string, string>  $errors
     */
    private function validateMailgun(array &$errors, FormUpdateMailSettingData $data): void
    {
        $this->requireFilled($errors, 'mailgun_domain', $data->mailgun_domain);
        $this->requireSecret(
            $errors,
            'mailgun_secret',
            $data->mailgun_secret,
            $this->currentSecret($this->settings->mailgun_secret, $data->clear_mailgun_secret),
        );
    }

    /**
     * 校验 SES 驱动配置。
     *
     * @param  array<string, string>  $errors
     */
    private function validateSes(array &$errors, FormUpdateMailSettingData $data): void
    {
        $this->requireSecret(
            $errors,
            'ses_key',
            $data->ses_key,
            $this->currentSecret($this->settings->ses_key, $data->clear_ses_key),
        );
        $this->requireSecret(
            $errors,
            'ses_secret',
            $data->ses_secret,
            $this->currentSecret($this->settings->ses_secret, $data->clear_ses_secret),
        );
        $this->requireFilled($errors, 'ses_region', $data->ses_region);
    }

    /**
     * 要求指定字段不为空。
     *
     * @param  array<string, string>  $errors
     */
    private function requireFilled(array &$errors, string $field, mixed $value): void
    {
        if (! filled($value)) {
            $errors[$field] = __('mail_settings.validation.required_for_enabled_driver');
        }
    }

    /**
     * 要求密钥字段存在新值或已有值。
     *
     * @param  array<string, string>  $errors
     */
    private function requireSecret(array &$errors, string $field, ?string $incoming, ?string $current): void
    {
        if (! filled($incoming) && ! filled($current)) {
            $errors[$field] = __('mail_settings.validation.secret_required_for_enabled_driver');
        }
    }

    /**
     * 根据清空标记解析当前密钥值。
     */
    private function currentSecret(?string $current, bool $clear): ?string
    {
        return $clear ? null : $current;
    }

    /**
     * 更新或清空指定密钥配置项。
     */
    private function updateSecret(string $property, ?string $value, bool $clear): void
    {
        if (filled($value)) {
            $this->settings->{$property} = trim($value);

            return;
        }

        if ($clear) {
            $this->settings->{$property} = null;
        }
    }

    /**
     * 将空白字符串统一归一为 null。
     */
    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
