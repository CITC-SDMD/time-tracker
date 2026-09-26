<?php

// The activity check (docs/DEVELOPMENT_PLAN.md §16). Everything that decides a level lives here so it can be tuned from
// real data without touching the desktop app: change a number, then `php artisan tracker:reevaluate-days`.
return [

    // Process names (lowercase, without ".exe", matched as a whole name or a prefix) of programs made to move the
    // mouse, click or press keys for a person. The desktop app gets this list in every sync answer and reports only the
    // names from it that it finds running; every other process name stays on the computer.
    'macro_tools' => [
        'autohotkey', 'autohotkey32', 'autohotkey64', 'autohotkeyu32', 'autohotkeyu64', 'autohotkeyux',
        'tinytask', 'pulover', 'macrorecorder', 'macroexpress', 'jitterclick', 'mousejiggler', 'mouse_jiggler',
        'movemouse', 'caffeine', 'autoclicker', 'opautoclicker', 'gsautoclicker', 'murgeeclicker', 'ghostmouse',
        'wiggle', 'jiggler', 'keepnite', 'nomousejiggler',
    ],

    // a chunk is "software input" when this share of its events were sent by software and it has enough events
    'software_input' => [
        'min_events' => 30,
        'min_share' => 0.80,
        'min_minutes' => 20,
    ],

    // seconds in which the system's last-input time moved although no hardware input arrived
    'input_without_hardware' => [
        'min_minutes' => 15,
    ],

    // a chunk that looks like a scripted or hardware wiggle
    'robotic_pattern' => [
        'min_mouse_events' => 20,
        'max_interval_cv' => 10,      // regularity of the time between moves, times 100 (0 = perfectly regular)
        'min_tiny_move_share' => 90,  // moves of a few pixels, in percent
        'max_keys' => 2,
        'max_clicks' => 2,
        'min_minutes' => 60,
    ],

    'mouse_only_hours' => [
        'max_clicks_per_chunk' => 3,
        'min_minutes' => 90,
        'max_distinct_windows' => 1,
    ],

    // how many rules of each weight make each level
    'levels' => [
        'strong_if_strong' => 1,
        'strong_if_medium' => 2,
        'review_if_medium' => 1,
        'review_if_weak' => 2,
    ],
];
