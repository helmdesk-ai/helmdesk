<?php

namespace App\Models;

use App\Casts\ChannelSettingsCast;
use App\Data\Channel\Telegram\ChannelTelegramSettingsData;
use App\Data\Channel\Web\ChannelWebSettingsData;
use App\Enums\ChannelType;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property ChannelType $type
 * @property string $name
 * @property string|null $description
 * @property string $code
 * @property string|null $reception_plan_id
 * @property string|null $reception_plan_version_id
 * @property ChannelWebSettingsData|ChannelTelegramSettingsData $settings
 * @property string|null $telegram_bot_token
 * @property string|null $first_embed_host
 * @property Carbon|null $first_embed_at
 * @property string|null $last_embed_host
 * @property Carbon|null $last_embed_at
 * @property mixed $use_factory
 * @property int|null $reception_plan_versions_count
 * @property-read ReceptionPlan|null $receptionPlan
 * @property-read ReceptionPlanVersion|null $receptionPlanVersion
 *
 * @method static \Database\Factories\ChannelFactory<self> factory($count = null, $state = [])
 */
class Channel extends Model
{
    /**
     * 渠道模型，保存网站渠道等访客入口的展示、身份和嵌入配置。
     * 渠道绑定接待方案（reception_plan_id）并自动跟随其最新已发布版本；新会话创建时按解析出的最新版锁定快照。
     */

    /** @use HasFactory<ChannelFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /**
     * 返回渠道字段类型转换配置。
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'settings' => ChannelSettingsCast::class,
            'telegram_bot_token' => 'encrypted',
            'first_embed_at' => 'datetime',
            'last_embed_at' => 'datetime',
        ];
    }

    /**
     * 渠道绑定的接待方案；渠道运行时自动使用该方案的最新已发布版本。
     */
    public function receptionPlan(): BelongsTo
    {
        return $this->belongsTo(ReceptionPlan::class, 'reception_plan_id');
    }

    /**
     * 渠道历史上部署的接待方案版本关系；保留以兼容旧数据，运行时已改由方案最新版解析。
     */
    public function receptionPlanVersion(): BelongsTo
    {
        return $this->belongsTo(ReceptionPlanVersion::class, 'reception_plan_version_id');
    }

    /**
     * 注册渠道 code 生成逻辑。
     */
    protected static function booted(): void
    {
        static::creating(function (Channel $channel) {
            if (! filled($channel->code)) {
                $channel->code = static::generateUniqueCode($channel->type ?? ChannelType::Web);
            }
        });
    }

    /**
     * 生成指定渠道类型的唯一公开 code。
     */
    private static function generateUniqueCode(ChannelType $type): string
    {
        $prefix = match ($type) {
            ChannelType::Web => 'wch',
            ChannelType::Telegram => 'tg',
        };

        do {
            $code = $prefix.'_'.Str::lower(Str::random(12));
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }
}
