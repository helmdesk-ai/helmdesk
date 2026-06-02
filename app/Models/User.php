<?php

namespace App\Models;

use App\Data\User\UserNotificationPreferencesData;
use App\Enums\SystemRole;
use App\Enums\UserOnlineStatus;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use App\Services\Localization\LocalePreference;
use App\Settings\MailSettings;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $name
 * @property string $email
 * @property string|null $avatar
 * @property string $locale
 * @property string|null $timezone
 * @property SystemRole $role
 * @property string|null $nickname
 * @property UserOnlineStatus $online_status
 * @property Carbon|null $last_active_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $is_super_admin
 * @property Carbon|null $deleted_at
 * @property mixed $use_factory
 * @property int|null $assigned_conversations_count
 * @property int|null $avatar_attachments_count
 * @property-read Collection|Conversation[] $assignedConversations
 * @property-read Attachment|null $avatarAttachment
 *
 * @method static \Database\Factories\UserFactory<self> factory($count = null, $state = [])
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /**
     * 用户模型，保存后台账号、角色、在线状态和超级管理员标记。
     */

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, TwoFactorAuthenticatable;

    use SoftDeletes;

    protected $table = 'users';

    /** @var list<string> 可批量写入的用户字段。 */
    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
        'avatar',
        'locale',
        'timezone',
        'notification_preferences',
        'role',
        'nickname',
        'online_status',
        'last_active_at',
        'is_super_admin',
    ];

    /** @var list<string> 序列化用户时隐藏的敏感字段。 */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * 返回用户字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => UserNotificationPreferencesData::class.':default',
            'role' => SystemRole::class,
            'online_status' => UserOnlineStatus::class,
            'two_factor_confirmed_at' => 'datetime',
            'last_active_at' => 'datetime',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * 用户被物理删除时清理其个人快捷回复，避免私有内容因外键置空变成系统共享。
     */
    protected static function booted(): void
    {
        static::forceDeleted(function (User $user): void {
            CannedReply::query()
                ->where('user_id', $user->id)
                ->delete();
        });
    }

    /**
     * 当前分配给用户处理的会话。
     */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_user_id');
    }

    /**
     * 用户头像附件。
     */
    public function avatarAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')
            ->where('purpose', 'avatar')
            ->latestOfMany();
    }

    /**
     * 返回用户通知偏好。
     */
    public function notificationPreferences(): UserNotificationPreferencesData
    {
        return $this->notification_preferences;
    }

    /**
     * 返回通知和邮件使用的 Laravel 语言标识。
     */
    public function preferredLocale(): string
    {
        return LocalePreference::normalizeLaravel($this->locale);
    }

    /**
     * 在系统邮件启用且用户不是超级管理员时发送邮箱验证通知。
     */
    public function sendEmailVerificationNotification(): void
    {
        /** @var MailSettings $mailSettings */
        $mailSettings = app(MailSettings::class);
        $mailSettings->refresh();

        if ($this->is_super_admin || ! $mailSettings->enabled) {
            return;
        }

        $this->notify(new QueuedVerifyEmail);
    }

    /**
     * 在系统邮件启用时发送密码重置通知。
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        /** @var MailSettings $mailSettings */
        $mailSettings = app(MailSettings::class);
        $mailSettings->refresh();

        if (! $mailSettings->enabled) {
            return;
        }

        $this->notify(new QueuedResetPassword($token));
    }
}
