<?php

return [
    'check_success' => 'Connection test succeeded.',
    'validation_failed' => 'Validation failed. Please check your storage settings and try again.',
    'secret_required' => 'Secret Key is required.',
    'storage_not_selected' => 'Object storage is enabled, but no storage configuration is selected.',
    'storage_not_found' => 'Current storage configuration not found, please select again.',
    'storage_key_secret_required' => 'Storage configuration requires Key/Secret. Please update credentials first.',
    'connection_check_success' => 'Connection test succeeded.',
    'connection_check_failed' => 'Connection test failed. Please check your storage settings and network connectivity.',
    'cors_check_failed' => 'Connection succeeded, but the Bucket CORS configuration could not be read. Make sure the key can read CORS settings and CORS is configured for browser direct uploads.',
    'cors_direct_upload_required' => 'Connection succeeded, but Bucket CORS does not allow browser direct uploads from the current site (:origin). Allow POST and PUT methods, include Content-Type in Allowed Headers, and expose ETag.',
    'profile_is_active_cannot_delete' => 'This profile is currently active and cannot be deleted.',
    'profile_is_referenced_cannot_delete' => 'This profile is referenced by attachments and cannot be deleted.',
    'profile_credentials_pair_required' => 'Updating credentials requires both Key and Secret.',
    'drivers' => [
        'local' => 'Local storage',
        's3' => 'S3-compatible storage',
    ],
    'status' => [
        'active' => 'Active',
        'disabled' => 'Disabled',
    ],
    'providers' => [
        'aws' => 'Amazon S3',
        'r2' => 'Cloudflare R2',
        'aliyun' => 'Alibaba Cloud',
        'tencent' => 'Tencent Cloud',
        'baidu' => 'Baidu Cloud',
        'qiniu' => 'Qiniu Cloud',
        'huawei' => 'Huawei Cloud',
        'ucloud' => 'UCloud',
        'minio' => 'MinIO',
        'rustfs' => 'RustFS',
    ],
];
