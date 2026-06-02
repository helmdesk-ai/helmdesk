<?php

namespace App\Data\User;

use App\Enums\UserOnlineStatus;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * 当前管理员更新在线状态的表单数据。
 */
class FormUpdateMyOnlineStatusData extends Data
{
    public function __construct(
        public UserOnlineStatus $online_status,
    ) {}

    /**
     * 校验在线状态提交值。
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'online_status' => ['required', 'integer', Rule::enum(UserOnlineStatus::class)],
        ];
    }
}
