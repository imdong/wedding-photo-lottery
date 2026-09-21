<?php

return [
    'draw_count' => 1,
    'exclude_previous_winners' => filter_var(
        env('DRAW_EXCLUDE_PREVIOUS_WINNERS', true),
        FILTER_VALIDATE_BOOL,
    ),
];
