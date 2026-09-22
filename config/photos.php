<?php

$allowedMimeTypes = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('PHOTO_ALLOWED_MIME_TYPES', 'image/jpeg,image/png,image/webp,image/heic')),
)));

return [
    'max_batch_size' => (int) env('PHOTO_MAX_BATCH_SIZE', 20),
    'max_original_size_bytes' => (int) env('PHOTO_MAX_ORIGINAL_SIZE_MB', 100) * 1024 * 1024,
    'max_display_size_bytes' => (int) env('PHOTO_MAX_DISPLAY_SIZE_MB', 20) * 1024 * 1024,
    'large_original_size_bytes' => (int) env('PHOTO_LARGE_ORIGINAL_SIZE_MB', 20) * 1024 * 1024,
    'allowed_mime_types' => $allowedMimeTypes,
];
