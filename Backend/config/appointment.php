<?php

return [
    'default_duration_minutes' => env('APPOINTMENT_DEFAULT_DURATION_MINUTES', 20),
    'default_break_minutes' => env('APPOINTMENT_DEFAULT_BREAK_MINUTES', 10),
    'business_timezone' => env('APPOINTMENT_BUSINESS_TIMEZONE', 'Asia/Kolkata'),
    'working_days' => [1, 2, 3, 4, 5, 6, 7],
    'working_start_time' => env('APPOINTMENT_WORKING_START_TIME', '00:00'),
    'working_end_time' => env('APPOINTMENT_WORKING_END_TIME', '00:00'),
];
