<?php

namespace App\Providers;

use App\Contracts\ContactTagFilterStrategy;
use App\Enums\UserPermission;
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
        //  - Go 端已经通过 sqlite3_auto_extension 注册过 → 探测命中，PHP 跳过 loadExtension；
        //  - 纯 PHP CLI 启动（artisan test / tinker 等） → 探测失败，PHP 主动 loadExtension。
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            app(SqliteVecExtensionLoader::class)->ensureLoadedFor($event->connection);
        });

        // 两套权限 Gate 服务于两种调用语法，底层均委托给 User::hasPermission()：
        //  - 参数化 'user.permission'：供 Action / 中间件传入 UserPermission 枚举使用；
        //  - 每个权限名一个同名 Gate：供路由 'can:users.view' 中间件使用（can 中间件只能传字符串 ability，无法带枚举参数）。
        Gate::define('user.permission', function (User $actor, UserPermission|string $permission): bool {
            return $actor->hasPermission($permission);
        });

        foreach (UserPermission::cases() as $permission) {
            Gate::define($permission->value, function (User $actor) use ($permission): bool {
                return $actor->hasPermission($permission);
            });
        }

        // 删除/更新后台成员的关系判定 Gate（与按权限名注册的 Gate 区分：这里还要校验超管与本人）。
        Gate::define('users.removeMember', function (User $actor, User $target): bool {
            if ($target->is_super_admin) {
                return false;
            }

            if ((string) $actor->id === (string) $target->id) {
                return false;
            }

            return $actor->hasPermission(UserPermission::UsersDelete);
        });

        Gate::define('users.updateProfile', function (User $actor, User $target): bool {
            if ($target->is_super_admin) {
                return false;
            }

            return $actor->hasPermission(UserPermission::UsersEdit);
        });
    }

    /**
     * 判断当前异常是否来自 settings 表尚未创建。
     */
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
