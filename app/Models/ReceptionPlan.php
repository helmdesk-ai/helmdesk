<?php

namespace App\Models;

use Database\Factories\ReceptionPlanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property array|null $persona_config
 * @property string|null $global_instructions
 * @property array|null $reception_config
 * @property array|null $task_config
 * @property array $capabilities
 * @property array $always_on_tools
 * @property array $knowledge_base_ids
 * @property array $strategy_config
 * @property array $auto_messages_config
 * @property array|null $translation_config
 * @property mixed $use_factory
 * @property int|null $workspaces_count
 * @property int|null $versions_count
 * @property-read Workspace $workspace
 * @property-read Collection|ReceptionPlanVersion[] $versions
 *
 * @method static \Database\Factories\ReceptionPlanFactory<self> factory($count = null, $state = [])
 */
class ReceptionPlan extends Model
{
    /**
     * 接待方案草稿，承载 workspace 内 AI 接待的人设、全局指令、接待智能体与任务智能体。
     * 发布时由 CompileReceptionPlanAction 生成不可变快照写入 ReceptionPlanVersion；
     * 草稿编辑不影响线上行为。
     */

    /** @use HasFactory<ReceptionPlanFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /**
     * Plan JSON 配置块的类型转换。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'persona_config' => 'array',
            'reception_config' => 'array',
            'task_config' => 'array',
            'capabilities' => 'array',
            'always_on_tools' => 'array',
            'knowledge_base_ids' => 'array',
            'strategy_config' => 'array',
            'auto_messages_config' => 'array',
            'translation_config' => 'array',
        ];
    }

    /**
     * Plan 所属工作区。
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Plan 的所有版本（含已归档）。版本表不软删，软删 Plan 后版本仍可被历史会话解析。
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ReceptionPlanVersion::class);
    }

    /**
     * 把表单字段拼成设置块中的 default_model 结构。
     *
     * @return array<string, mixed>
     */
    public static function buildModelInvocation(string $aiModelId): array
    {
        return ['ai_model_id' => $aiModelId];
    }
}
