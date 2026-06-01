<?php

namespace App\Data\Reception;

use App\Enums\Reception\ReceptionRoutingMode;
use DateTimeZone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Spatie\LaravelData\Data;

/**
 * 接待方案流程策略配置。
 */
class ReceptionStrategyConfigData extends Data
{
    private const REQUIRED_KEYS = [
        'reception_mode',
        'unassigned_ai_takeover_enabled',
        'unassigned_ai_takeover_timeout_seconds',
        'teammate_no_response_ai_takeover_enabled',
        'teammate_no_response_ai_takeover_timeout_seconds',
        'important_contact_ai_careful_reply_enabled',
        'important_contact_ai_handoff_hint_enabled',
        'important_contact_human_first_when_online_enabled',
        'quote_visitor_message_enabled',
        'handoff_available_notice',
        'handoff_no_teammate_notice',
        'ai_unavailable_notice',
        'business_hours',
    ];

    /**
     * 创建接待流程策略配置。
     */
    public function __construct(
        public ReceptionRoutingMode $reception_mode,
        public bool $unassigned_ai_takeover_enabled,
        public int $unassigned_ai_takeover_timeout_seconds,
        public bool $teammate_no_response_ai_takeover_enabled,
        public int $teammate_no_response_ai_takeover_timeout_seconds,
        public bool $important_contact_ai_careful_reply_enabled,
        public bool $important_contact_ai_handoff_hint_enabled,
        public bool $important_contact_human_first_when_online_enabled,
        public bool $quote_visitor_message_enabled,
        public string $handoff_available_notice,
        public string $handoff_no_teammate_notice,
        public string $ai_unavailable_notice,
        public ?ReceptionBusinessHoursData $business_hours,
    ) {}

    /**
     * 从完整配置数组创建配置。
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $raw)) {
                throw new LogicException("Reception strategy config [{$key}] is required.");
            }
        }

        return new self(
            reception_mode: ReceptionRoutingMode::from($raw['reception_mode']),
            unassigned_ai_takeover_enabled: $raw['unassigned_ai_takeover_enabled'],
            unassigned_ai_takeover_timeout_seconds: $raw['unassigned_ai_takeover_timeout_seconds'],
            teammate_no_response_ai_takeover_enabled: $raw['teammate_no_response_ai_takeover_enabled'],
            teammate_no_response_ai_takeover_timeout_seconds: $raw['teammate_no_response_ai_takeover_timeout_seconds'],
            important_contact_ai_careful_reply_enabled: $raw['important_contact_ai_careful_reply_enabled'],
            important_contact_ai_handoff_hint_enabled: $raw['important_contact_ai_handoff_hint_enabled'],
            important_contact_human_first_when_online_enabled: $raw['important_contact_human_first_when_online_enabled'],
            quote_visitor_message_enabled: $raw['quote_visitor_message_enabled'],
            handoff_available_notice: trim($raw['handoff_available_notice']),
            handoff_no_teammate_notice: trim($raw['handoff_no_teammate_notice']),
            ai_unavailable_notice: trim($raw['ai_unavailable_notice']),
            business_hours: $raw['business_hours'] === null ? null : ReceptionBusinessHoursData::fromArray($raw['business_hours']),
        );
    }

    /**
     * 返回新接待方案的默认流程策略。
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'reception_mode' => ReceptionRoutingMode::AiFirst->value,
            'unassigned_ai_takeover_enabled' => false,
            'unassigned_ai_takeover_timeout_seconds' => 120,
            'teammate_no_response_ai_takeover_enabled' => true,
            'teammate_no_response_ai_takeover_timeout_seconds' => 300,
            'important_contact_ai_careful_reply_enabled' => true,
            'important_contact_ai_handoff_hint_enabled' => true,
            'important_contact_human_first_when_online_enabled' => false,
            'quote_visitor_message_enabled' => false,
            'handoff_available_notice' => __('reception.defaults.handoff_available_notice'),
            'handoff_no_teammate_notice' => __('reception.defaults.handoff_no_teammate_notice'),
            'ai_unavailable_notice' => __('reception.defaults.ai_unavailable_notice'),
            'business_hours' => null,
        ];
    }

    /**
     * 返回表单中策略字段的校验规则。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function formRules(): array
    {
        return [
            'strategy_config' => ['required', 'array'],
            'strategy_config.reception_mode' => ['required', Rule::enum(ReceptionRoutingMode::class)],
            'strategy_config.unassigned_ai_takeover_enabled' => ['required', 'boolean'],
            'strategy_config.unassigned_ai_takeover_timeout_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'strategy_config.teammate_no_response_ai_takeover_enabled' => ['required', 'boolean'],
            'strategy_config.teammate_no_response_ai_takeover_timeout_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'strategy_config.important_contact_ai_careful_reply_enabled' => ['required', 'boolean'],
            'strategy_config.important_contact_ai_handoff_hint_enabled' => ['required', 'boolean'],
            'strategy_config.important_contact_human_first_when_online_enabled' => ['required', 'boolean'],
            'strategy_config.quote_visitor_message_enabled' => ['required', 'boolean'],
            'strategy_config.handoff_available_notice' => ['required', 'string', 'max:500'],
            'strategy_config.handoff_no_teammate_notice' => ['required', 'string', 'max:500'],
            'strategy_config.ai_unavailable_notice' => ['required', 'string', 'max:500'],
            'strategy_config.business_hours' => ['nullable', 'array'],
            'strategy_config.business_hours.timezone' => ['required_with:strategy_config.business_hours', 'string', 'max:60'],
            'strategy_config.business_hours.outside_hours_notice' => ['required_with:strategy_config.business_hours', 'string', 'max:200'],
            'strategy_config.business_hours.schedule' => ['required_with:strategy_config.business_hours', 'array', 'size:7'],
            'strategy_config.business_hours.schedule.*.day' => ['required_with:strategy_config.business_hours.schedule', 'integer', 'between:1,7'],
            'strategy_config.business_hours.schedule.*.enabled' => ['required_with:strategy_config.business_hours.schedule', 'boolean'],
            'strategy_config.business_hours.schedule.*.open' => ['required_with:strategy_config.business_hours.schedule', 'string', 'date_format:H:i'],
            'strategy_config.business_hours.schedule.*.close' => ['required_with:strategy_config.business_hours.schedule', 'string', 'date_format:H:i'],
        ];
    }

    /**
     * 校验策略字段的跨字段约束。
     */
    public static function validateForm(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            $strategy = is_array($data['strategy_config'] ?? null) ? $data['strategy_config'] : [];
            $businessHours = $strategy['business_hours'] ?? null;
            if (! is_array($businessHours)) {
                return;
            }

            if (! in_array($businessHours['timezone'] ?? '', DateTimeZone::listIdentifiers(), true)) {
                $validator->errors()->add('strategy_config.business_hours.timezone', __('validation.in', ['attribute' => 'timezone']));
            }

            if (! is_array($businessHours['schedule'] ?? null)) {
                return;
            }

            $days = [];
            foreach ($businessHours['schedule'] as $index => $day) {
                if (! is_array($day)) {
                    continue;
                }

                $dayNumber = (int) ($day['day'] ?? 0);
                if (isset($days[$dayNumber])) {
                    $validator->errors()->add("strategy_config.business_hours.schedule.{$index}.day", __('validation.distinct', ['attribute' => 'day']));
                }
                $days[$dayNumber] = true;

                if (! filter_var($day['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $open = $day['open'] ?? null;
                $close = $day['close'] ?? null;
                $openMinutes = is_string($open) ? self::timeToMinutes($open) : null;
                $closeMinutes = is_string($close) ? self::closeTimeToMinutes($close) : null;

                if ($openMinutes !== null && $closeMinutes !== null && $closeMinutes <= $openMinutes) {
                    $validator->errors()->add("strategy_config.business_hours.schedule.{$index}.close", __('validation.after', ['attribute' => 'close', 'date' => 'open']));
                }
            }
        });
    }

    /**
     * 返回可写入草稿和版本快照的数组。
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'reception_mode' => $this->reception_mode->value,
            'unassigned_ai_takeover_enabled' => $this->unassigned_ai_takeover_enabled,
            'unassigned_ai_takeover_timeout_seconds' => $this->unassigned_ai_takeover_timeout_seconds,
            'teammate_no_response_ai_takeover_enabled' => $this->teammate_no_response_ai_takeover_enabled,
            'teammate_no_response_ai_takeover_timeout_seconds' => $this->teammate_no_response_ai_takeover_timeout_seconds,
            'important_contact_ai_careful_reply_enabled' => $this->important_contact_ai_careful_reply_enabled,
            'important_contact_ai_handoff_hint_enabled' => $this->important_contact_ai_handoff_hint_enabled,
            'important_contact_human_first_when_online_enabled' => $this->important_contact_human_first_when_online_enabled,
            'quote_visitor_message_enabled' => $this->quote_visitor_message_enabled,
            'handoff_available_notice' => $this->handoff_available_notice,
            'handoff_no_teammate_notice' => $this->handoff_no_teammate_notice,
            'ai_unavailable_notice' => $this->ai_unavailable_notice,
            'business_hours' => $this->business_hours?->toArray(),
        ];
    }

    /**
     * 将 H:i 时间转换为当天分钟数。
     */
    private static function timeToMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    /**
     * 结束时间 00:00 表示当天结束，即 24:00。
     */
    private static function closeTimeToMinutes(string $time): ?int
    {
        $minutes = self::timeToMinutes($time);

        return $minutes === 0 ? 1440 : $minutes;
    }
}
