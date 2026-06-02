<?php

namespace App\Actions\Reception\Plan;

use App\Enums\ReceptionPlanVersionStatus;
use App\Models\ReceptionPlan;
use App\Models\ReceptionPlanVersion;
use App\Models\SystemContext;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 在方案保存时确保产出最新版本快照（保存即发布）。
 * 仅当编译结果相对最新版本实际发生变化时才建新版，避免无意义的版本行刷爆。
 * 版本号在事务内按 max(version_number)+1 计算，复合唯一索引兜底并发并重试。
 */
class EnsureReceptionPlanVersionAction
{
    use AsAction;

    /**
     * 并发冲突时的最大重试次数，避免极端情况下死循环。
     */
    private const MAX_VERSION_NUMBER_RETRIES = 3;

    /**
     * 编译当前草稿并与最新版本对比；有变化则建新版返回，无变化返回 null。
     */
    public function handle(SystemContext $systemContext, ReceptionPlan $plan, ?User $publisher): ?ReceptionPlanVersion
    {
        $compiled = CompileReceptionPlanAction::run($systemContext, $plan);

        $latest = ReceptionPlanVersion::query()
            ->where('reception_plan_id', $plan->id)
            ->orderByDesc('version_number')
            ->first();

        if ($latest !== null && $this->isSameConfig($latest, $compiled)) {
            return null;
        }

        $attempt = 0;
        while (true) {
            try {
                return DB::transaction(function () use ($plan, $publisher, $compiled): ReceptionPlanVersion {
                    $nextNumber = (int) (ReceptionPlanVersion::query()
                        ->where('reception_plan_id', $plan->id)
                        ->max('version_number') ?? 0) + 1;

                    return ReceptionPlanVersion::query()->create([
                        'reception_plan_id' => $plan->id,
                        'version_number' => $nextNumber,
                        'description' => null,
                        'snapshot_config' => $compiled['snapshot_config'],
                        'compiled_config' => $compiled['compiled_config'],
                        'status' => ReceptionPlanVersionStatus::Published,
                        'published_at' => now(),
                        'published_by_user_id' => $publisher?->id,
                    ]);
                });
            } catch (UniqueConstraintViolationException $e) {
                $attempt++;
                if ($attempt >= self::MAX_VERSION_NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 判断最新版本与新编译结果是否完全一致。
     * 同时比对 compiled_config 与 snapshot_config —— strategy / auto_messages / translation 仅进 snapshot，
     * 只比 compiled 会漏掉这些变化导致渠道跟不上新配置。关联数组 == 做深比，忽略键序。
     *
     * @param  array{snapshot_config: array<string, mixed>, compiled_config: array<string, mixed>}  $compiled
     */
    private function isSameConfig(ReceptionPlanVersion $latest, array $compiled): bool
    {
        $latestCompiled = is_array($latest->compiled_config) ? $latest->compiled_config : [];
        $latestSnapshot = is_array($latest->snapshot_config) ? $latest->snapshot_config : [];

        return $latestCompiled == $compiled['compiled_config']
            && $latestSnapshot == $compiled['snapshot_config'];
    }
}
