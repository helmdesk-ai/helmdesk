<?php

namespace App\Data\Teammate;

use App\Data\EnumOptionData;
use Spatie\LaravelData\Data;

/**
 * 客服列表页 props。
 * 由 resources/js/pages/teammates/Index.vue 消费，用于展示客服账号列表和状态文案。
 */
class ShowTeammateListPagePropsData extends Data
{
    public function __construct(
        /** @var ListTeammateItemData[] */
        public array $user_list,

        /** @var EnumOptionData[] */
        public array $online_status_options,
        public bool $can_create,
    ) {}
}
