<?php

// Public demo uploads: testbench's public directory is served directly, no storage:link.
return [
    'driver' => 'local',
    'root' => public_path('uploads'),
    'url' => '/uploads',
    'visibility' => 'public',
    'throw' => true,
];
