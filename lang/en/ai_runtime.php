<?php

return [
    'workspace_ai_updated' => 'Workspace AI settings saved.',

    'invalid_workspace_model' => 'The selected model is invalid or inactive. Please choose another.',
    'workspace_default_model_unavailable' => 'The workspace default model is unavailable. Please fix it in default model settings or choose a model for this reception plan.',

    'max_concurrency_exceeds_global' => 'Max concurrency cannot exceed this workspace AI max concurrency setting (:max).',
    'global_max_concurrency_below_model_limit' => 'The workspace AI max concurrency setting cannot be lower than an existing model limit. Model ":model" is set to :max.',

    'model_in_use_workspace' => 'This model is the workspace default and cannot be disabled or deleted. Please change the workspace default model first.',
    'model_in_use_reception_plan' => 'This model is referenced by a reception plan (draft or published version) and cannot be disabled or deleted. Update the affected reception plan to use another model first.',
    'provider_in_use_workspace' => 'A model under this provider is the workspace default. Please change the workspace default model first.',
    'provider_in_use_reception_plan' => 'A model under this provider is referenced by a reception plan. Please update the affected reception plan to use another model first.',

    'model_status' => [
        'model_inactive' => 'The model is inactive',
        'provider_inactive' => 'The model provider is inactive',
        'deleted' => 'The model has been deleted',
        'missing_after_delete' => 'No usable model configured',
    ],
];
