<?php

namespace App\Providers;

use App\Contracts\ContactTagFilterStrategy;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\Database\SqliteVecExtensionLoader;
use App\Services\KnowledgeBase\Parsing\DocumentParserManager;
use App\Services\KnowledgeBase\Parsing\DocxDocumentParser;
use App\Services\KnowledgeBase\Parsing\PdfDocumentParser;
use App\Services\KnowledgeBase\Parsing\TextDocumentParser;
use App\Services\KnowledgeBase\Parsing\XlsxDocumentParser;
use App\Services\Mail\ApplyMailSettings;
use App\Services\Tag\PivotContactTagFilterStrategy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * 注册应用服务。
     */
    public function register(): void
    {
        $this->app->bind(ContactTagFilterStrategy::class, PivotContactTagFilterStrategy::class);

        // 知识库文档解析器：按注册顺序匹配 mime/扩展，命中即用。
        // TextDocumentParser 兜底，对未知 mime/扩展也接收，避免上层失配抛错。
        $this->app->singleton(DocumentParserManager::class, function ($app): DocumentParserManager {
            return new DocumentParserManager([
                $app->make(PdfDocumentParser::class),
                $app->make(DocxDocumentParser::class),
                $app->make(XlsxDocumentParser::class),
                $app->make(TextDocumentParser::class),
            ]);
        });
    }

    /**
     * 启动应用服务。
     */
    public function boot(): void
    {
        // 经 HTTPS 反代 / 内网穿透时，到达后端的请求是明文 HTTP，URL 生成默认跟随请求 scheme 会产出 http://，
        // 使 HTTPS 页面加载 http 资源被浏览器按混合内容拦截。APP_URL 配置为 https 时统一强制按 https 生成。
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        try {
            app(ApplyMailSettings::class)->apply(applyBaseUrl: true);
        } catch (Throwable $e) {
            if (! (app()->runningInConsole() && $this->isMissingSettingsTableException($e))) {
                throw $e;
            }
        }

        $this->configureAuthMailMessages();

        // sqlite_rag 连接初始化时确保 sqlite-vec 扩展可用。
        // 真正的加载方式由 SqliteVecExtensionLoader 内部探测决定：
        //  - Go 端已经通过 sqlite3_auto_extension 注册过 → 探测命中，PHP 不再 loadExtension；
        //  - 纯 PHP CLI 启动（artisan test / tinker 等） → 探测失败，PHP 主动 loadExtension。
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            app(SqliteVecExtensionLoader::class)->ensureLoadedFor($event->connection);
        });

        // 管理中心权限
        Gate::define('workspace.canAccessManageCenter', function (User $actor, mixed $scope = null): bool {
            return $actor->is_super_admin
                || in_array($actor->role, [WorkspaceRole::Owner, WorkspaceRole::Admin], true);
        });

        Gate::define('workspace.manageAi', function (User $actor, mixed $scope = null): bool {
            return $actor->is_super_admin || $actor->role === WorkspaceRole::Owner;
        });

        // 从后台移除成员权限（仅普通成员可被移除，不删除超级管理员）
        Gate::define('workspace-users.removeMember', function (User $actor, mixed $scopeOrTarget, ?User $target = null): bool {
            $target ??= $scopeOrTarget instanceof User ? $scopeOrTarget : null;
            if (! $target instanceof User) {
                return false;
            }

            if ($target->is_super_admin) {
                return false;
            }

            if ((string) $actor->id === (string) $target->id) {
                return false;
            }

            if ($actor->is_super_admin || $actor->role === WorkspaceRole::Owner) {
                return true;
            }

            if ($actor->role !== WorkspaceRole::Admin) {
                return false;
            }

            return $target->role === WorkspaceRole::Operator;
        });

        // 更新用户资料权限
        Gate::define('workspace-users.updateProfile', function (User $actor, mixed $scopeOrTarget, ?User $target = null): bool {
            $target ??= $scopeOrTarget instanceof User ? $scopeOrTarget : null;
            if (! $target instanceof User) {
                return false;
            }

            if ($actor->is_super_admin || $actor->role === WorkspaceRole::Owner) {
                return true;
            }

            if ($actor->role !== WorkspaceRole::Admin) {
                return false;
            }

            return (string) $actor->id === (string) $target->id
                || $target->role === WorkspaceRole::Operator;
        });

        Gate::define('workspace-users.canUpdateRole', function (User $actor, mixed $scopeOrTarget, ?User $target = null): bool {
            $target ??= $scopeOrTarget instanceof User ? $scopeOrTarget : null;

            return ($actor->is_super_admin || $actor->role === WorkspaceRole::Owner)
                && $target instanceof User
                && (string) $actor->id !== (string) $target->id;
        });

        Gate::define('workspace-users.updateRole', function (User $actor, mixed $scopeOrTarget, User|WorkspaceRole|null $targetOrRole = null, ?WorkspaceRole $newRole = null): bool {
            $target = $scopeOrTarget instanceof User ? $scopeOrTarget : null;
            if ($target === null && $targetOrRole instanceof User) {
                $target = $targetOrRole;
            }

            if ($newRole === null && $targetOrRole instanceof WorkspaceRole) {
                $newRole = $targetOrRole;
            }

            return ($actor->is_super_admin || $actor->role === WorkspaceRole::Owner)
                && $target instanceof User
                && $newRole instanceof WorkspaceRole
                && (string) $actor->id !== (string) $target->id
                && in_array($newRole, WorkspaceRole::assignableCases(), true);
        });
    }

    private function isMissingSettingsTableException(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'settings')
            && (str_contains($message, 'no such table') || str_contains($message, 'does not exist'));
    }

    /**
     * 注册认证相关邮件的自定义内容和发送前配置刷新逻辑。
     */
    private function configureAuthMailMessages(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            app(ApplyMailSettings::class)->apply(applyBaseUrl: true);

            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            return (new MailMessage)
                ->subject(__('mail.password_reset.subject'))
                ->line(__('mail.password_reset.line'))
                ->action(__('mail.password_reset.action'), $url);
        });

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            app(ApplyMailSettings::class)->apply(applyBaseUrl: true);

            return URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            app(ApplyMailSettings::class)->apply(applyBaseUrl: true);

            return (new MailMessage)
                ->subject(__('mail.email_verification.subject'))
                ->line(__('mail.email_verification.line'))
                ->action(__('mail.email_verification.action'), $url);
        });
    }
}
