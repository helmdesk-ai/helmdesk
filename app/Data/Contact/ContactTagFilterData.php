<?php

namespace App\Data\Contact;

use App\Enums\TagMatchMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * 联系人标签筛选数据。
 * 由后端组装后传给 resources/js/pages/contacts/Index.vue、Trash.vue 和 ContactDetailDrawer.vue，用于页面展示、抽屉详情或局部交互状态。
 */
class ContactTagFilterData extends Data
{
    /**
     * @param  list<ContactTagConditionData>  $include
     * @param  list<ContactTagConditionData>  $exclude
     */
    public function __construct(
        public array $include,
        public TagMatchMode $include_mode,
        public array $exclude,
        public TagMatchMode $exclude_mode,
        public bool $untagged_only,
    ) {}

    public static function unfiltered(): self
    {
        return new self(
            include: [],
            include_mode: TagMatchMode::Any,
            exclude: [],
            exclude_mode: TagMatchMode::Any,
            untagged_only: false,
        );
    }

    /**
     * 从 HTTP 请求构造 Spec。
     *
     * 当 untagged_only 为真时，其余条件一律忽略，避免语义冲突。
     */
    public static function fromRequest(Request $request): self
    {
        $untaggedOnly = self::parseBoolean($request->query('untagged_only'), 'untagged_only');

        if ($untaggedOnly) {
            return new self(
                include: [],
                include_mode: TagMatchMode::Any,
                exclude: [],
                exclude_mode: TagMatchMode::Any,
                untagged_only: true,
            );
        }

        $includeIds = self::normalizeIds($request->query('include_tag_ids', []));
        $excludeIds = self::normalizeIds($request->query('exclude_tag_ids', []));

        /*
         * include 与 exclude 的交集不做自动消歧：
         *
         *   "include any [A,B] + exclude any [B]" 在集合上等价于 "has A AND NOT has B"，
         *   是合法且常见的表达；若在此处强行去重会把语义悄悄放宽。
         *   由策略层按原样下发，交由 SQL 自然求值即可。
         */

        $includeMode = self::resolveMode($request->query('include_tag_mode'), 'include_tag_mode');
        $excludeMode = self::resolveMode($request->query('exclude_tag_mode'), 'exclude_tag_mode');

        $taggedAfter = self::parseDateTime($request->query('tag_tagged_after'), 'tag_tagged_after');
        $taggedBefore = self::parseDateTime($request->query('tag_tagged_before'), 'tag_tagged_before');

        return new self(
            include: array_map(
                static fn (string $id) => new ContactTagConditionData(
                    tag_id: $id,
                    tagged_after: $taggedAfter,
                    tagged_before: $taggedBefore,
                ),
                $includeIds,
            ),
            include_mode: $includeMode,
            exclude: array_map(
                static fn (string $id) => new ContactTagConditionData(tag_id: $id),
                $excludeIds,
            ),
            exclude_mode: $excludeMode,
            untagged_only: false,
        );
    }

    public function isEmpty(): bool
    {
        return $this->include === []
            && $this->exclude === []
            && ! $this->untagged_only;
    }

    /**
     * 过滤掉不在可用标签集合中的 tag_id（防刷/防过期）。
     *
     * @param  array<int, string>  $allowedTagIds
     */
    public function restrictedTo(array $allowedTagIds): self
    {
        $allowed = array_flip($allowedTagIds);

        $filter = static fn (array $conditions): array => array_values(array_filter(
            $conditions,
            static fn (ContactTagConditionData $c) => isset($allowed[$c->tag_id]),
        ));

        return new self(
            include: $filter($this->include),
            include_mode: $this->include_mode,
            exclude: $filter($this->exclude),
            exclude_mode: $this->exclude_mode,
            untagged_only: $this->untagged_only,
        );
    }

    /**
     * @return list<string>
     */
    public function includeTagIds(): array
    {
        return array_map(static fn (ContactTagConditionData $c) => $c->tag_id, $this->include);
    }

    /**
     * @return list<string>
     */
    public function excludeTagIds(): array
    {
        return array_map(static fn (ContactTagConditionData $c) => $c->tag_id, $this->exclude);
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private static function normalizeIds($raw): array
    {
        if (! is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [$raw];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $raw),
            static fn (string $v) => $v !== '',
        )));

        return $ids;
    }

    private static function parseBoolean(mixed $raw, string $field): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }

        return match ($raw) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => throw ValidationException::withMessages([
                $field => __('validation.boolean', ['attribute' => $field]),
            ]),
        };
    }

    private static function resolveMode(mixed $raw, string $field): TagMatchMode
    {
        if ($raw === null || $raw === '') {
            return TagMatchMode::Any;
        }

        if ($raw instanceof TagMatchMode) {
            return $raw;
        }

        if (is_string($raw) && ($mode = TagMatchMode::tryFrom($raw)) instanceof TagMatchMode) {
            return $mode;
        }

        throw ValidationException::withMessages([
            $field => __('validation.in', ['attribute' => $field]),
        ]);
    }

    private static function parseDateTime(mixed $raw, string $field): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            throw ValidationException::withMessages([
                $field => __('validation.date_format', ['attribute' => $field, 'format' => 'YYYY-MM-DD']),
            ]);
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => __('validation.date_format', ['attribute' => $field, 'format' => 'YYYY-MM-DD']),
            ]);
        }
    }
}
