<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.enabled', false);
        $this->migrator->add('mail.driver', 'smtp');
        $this->migrator->add('mail.from_address', null);
        $this->migrator->add('mail.from_name', null);
        $this->migrator->add('mail.smtp_host', null);
        $this->migrator->add('mail.smtp_port', 587);
        $this->migrator->add('mail.smtp_encryption', 'starttls');
        $this->migrator->add('mail.smtp_username', null);
        $this->migrator->addEncrypted('mail.smtp_password', null);
        $this->migrator->add('mail.smtp_local_domain', null);
        $this->migrator->add('mail.smtp_timeout', 10);
        $this->migrator->add('mail.mailgun_domain', null);
        $this->migrator->addEncrypted('mail.mailgun_secret', null);
        $this->migrator->add('mail.mailgun_endpoint', 'api.mailgun.net');
        $this->migrator->add('mail.mailgun_scheme', 'https');
        $this->migrator->addEncrypted('mail.postmark_token', null);
        $this->migrator->add('mail.postmark_message_stream_id', null);
        $this->migrator->addEncrypted('mail.resend_key', null);
        $this->migrator->addEncrypted('mail.ses_key', null);
        $this->migrator->addEncrypted('mail.ses_secret', null);
        $this->migrator->add('mail.ses_region', 'us-east-1');
        $this->migrator->addEncrypted('mail.ses_token', null);
        $this->migrator->add('mail.sendmail_path', '/usr/sbin/sendmail -bs -i');
    }

    public function down(): void
    {
        $this->migrator->delete('mail.enabled');
        $this->migrator->delete('mail.driver');
        $this->migrator->delete('mail.from_address');
        $this->migrator->delete('mail.from_name');
        $this->migrator->delete('mail.smtp_host');
        $this->migrator->delete('mail.smtp_port');
        $this->migrator->delete('mail.smtp_encryption');
        $this->migrator->delete('mail.smtp_username');
        $this->migrator->delete('mail.smtp_password');
        $this->migrator->delete('mail.smtp_local_domain');
        $this->migrator->delete('mail.smtp_timeout');
        $this->migrator->delete('mail.mailgun_domain');
        $this->migrator->delete('mail.mailgun_secret');
        $this->migrator->delete('mail.mailgun_endpoint');
        $this->migrator->delete('mail.mailgun_scheme');
        $this->migrator->delete('mail.postmark_token');
        $this->migrator->delete('mail.postmark_message_stream_id');
        $this->migrator->delete('mail.resend_key');
        $this->migrator->delete('mail.ses_key');
        $this->migrator->delete('mail.ses_secret');
        $this->migrator->delete('mail.ses_region');
        $this->migrator->delete('mail.ses_token');
        $this->migrator->delete('mail.sendmail_path');
    }
};
