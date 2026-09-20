<?php

return [
    'access_key_id' => env('Ali_Key'),
    'access_key_secret' => env('Ali_Secret'),

    'bucket' => env('ALIYUN_OSS_BUCKET', 'example-wedding-photos'),
    'region' => env('ALIYUN_OSS_REGION', 'cn-qingdao'),
    'endpoint' => env('ALIYUN_OSS_ENDPOINT', 'https://oss-cn-qingdao.aliyuncs.com'),
    'internal_endpoint' => env('ALIYUN_OSS_INTERNAL_ENDPOINT', 'https://oss-cn-qingdao-internal.aliyuncs.com'),
    'bucket_url' => env('ALIYUN_OSS_BUCKET_URL', 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com'),
    'public_url' => env('ALIYUN_OSS_PUBLIC_URL', 'https://cdn.example.com'),

    'role_arn' => env('ALIYUN_OSS_ROLE_ARN'),
    'sts_endpoint' => env('ALIYUN_STS_ENDPOINT', 'https://sts.cn-qingdao.aliyuncs.com'),
    'temporary_credential_ttl_seconds' => (int) env('ALIYUN_OSS_TEMPORARY_CREDENTIAL_TTL_SECONDS', 900),
    'image_url_ttl_seconds' => (int) env('ALIYUN_OSS_IMAGE_URL_TTL_SECONDS', 3600),
    'upload_prefix' => env('ALIYUN_OSS_UPLOAD_PREFIX', 'wedding/photos'),
];
