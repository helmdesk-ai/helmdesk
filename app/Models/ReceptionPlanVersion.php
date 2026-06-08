<?php

namespace App\Models;

use App\Enums\ReceptionPlanVersionStatus;
use Database\Factories\ReceptionPlanVersionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $reception_plan_id
 * @property int $version_number
 * @property string|null $description
 * @property array $snapshot_config
 * @property array $compiled_config
 * @property ReceptionPlanVersionStatus $status
 * @property Carbon|null $published_at
 * @property string|null $published_by_user_id
 * @property mixed $use_factory
 * @property int|null $plans_count
 * @property int|null $published_bies_count
 * @property int|null $channels_count
 * @property int|null $conversations_count
 * @property-read ReceptionPlan $plan
 * @property-read User|null $publishedBy
 * @property-read Collection|Channel[] $channels
 * @property-read Collection|Conversation[] $conversations
 *
 * @method static \Database\Factories\ReceptionPlanVersionFactory<self> factory($count = null, $state = [])
 */
class ReceptionPlanVersion extends Model
{
    /**
     * 接待方案版本，发布时刻的不可变快照。
     * snapshot_config 保留草稿原貌用于后台展示与对比；compiled_config 是 Go 运行时实际加载的形态。
     * 本表不软删；归档走 status=archived，历史会话仍可通过 ID 解析回完整 compiled_config。
     */

    /** @use HasFactory<ReceptionPlanVersionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * 返回版本快照字段类型转换配置。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_config' => 'array',
            'compiled_config' => 'array',
            'status' => ReceptionPlanVersionStatus::class,
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * 版本归属的接待方案；Plan 可能软删，但已发布版本仍需继续被历史会话解析回 compiled_config。
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ReceptionPlan::class, 'reception_plan_id')->withTrashed();
    }

    /**
     * 发布此版本的用户；用户软删后版本历史仍要显示 "v3 published by alice"，审计完整性必需。
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id')->withTrashed();
    }

    /**
     * 当前部署到本版本的所有渠道（一个渠道同一时刻只指向一个版本）。
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'reception_plan_version_id');
    }

    /**
     * 锁定到本版本的所有会话；已建会话锁定创建时的版本，便于回放与对账。
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'reception_plan_version_id');
    }
}
