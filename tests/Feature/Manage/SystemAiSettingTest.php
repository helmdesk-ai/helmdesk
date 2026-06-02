<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('移除系统AI运行时路由', function () {
    expect(Route::has('admin.manage.ai.runtime'))->toBeFalse()
        ->and(Route::has('admin.manage.ai.runtime.update'))->toBeFalse();
});

it('移除系统级别AI运行时列来自结构', function () {
    expect(Schema::hasColumn('systems', 'default_llm_model_id'))->toBeFalse()
        ->and(Schema::hasColumn('systems', 'global_max_concurrency'))->toBeFalse()
        ->and(Schema::hasColumn('systems', 'overload_message'))->toBeFalse();
});

it('移除废弃的独立助理表结构', function () {
    expect(Schema::hasTable('agents'))->toBeFalse();
});
