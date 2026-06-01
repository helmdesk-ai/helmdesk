<?php

use Illuminate\Support\Facades\File;

test('AI 助手模型选择器会恢复历史选择并在无历史时默认第一个可用模型', function (): void {
    $contents = File::get(resource_path('js/components/common/AiAssistantWidget.vue'));

    expect($contents)
        ->toContain('const loadStoredModelSelection = (): StoredModelSelection | null =>')
        ->toContain('const selectFirstAvailableModel = (): void =>')
        ->toContain('const firstOption = modelOptions.value[0];')
        ->toContain('selectedModelId.value = firstOption.value;')
        ->toContain('selectedModelId.value = matched.value;')
        ->toContain('storeSelectedModel(value);');

    expect($contents)->toMatch('/const remembered = loadStoredModelSelection\(\);\s+if \(!remembered\) {\s+selectFirstAvailableModel\(\);\s+return;\s+}/');
});
