<?php

return [
    'draw_count' => 1,
    'max_photos_per_user' => max(1, (int) env('DRAW_MAX_PHOTOS_PER_USER', 15)),
    'exclude_previous_winners' => filter_var(
        env('DRAW_EXCLUDE_PREVIOUS_WINNERS', true),
        FILTER_VALIDATE_BOOL,
    ),
];
