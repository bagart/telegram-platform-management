<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Module Activation',
        'description' => 'Enable or disable modules for a bot. Changes apply to new updates immediately.',
        'select_bot' => 'Select a bot',
        'no_bots' => 'No bots available.',
        'no_modules' => 'No modules are registered.',
        'column_module' => 'Module',
        'column_capabilities' => 'Capabilities',
        'column_state' => 'State',
        'state_enabled' => 'Enabled',
        'state_disabled' => 'Disabled',
        'enable' => 'Enable',
        'disable' => 'Disable',
    ],
    'enabled_flash' => 'Module ":module" is now enabled for bot ":bot".',
    'disabled_flash' => 'Module ":module" is now disabled for bot ":bot".',
    'blocked' => 'Enabling the module was blocked.',
    'concurrent_modification' => 'The module state changed concurrently; refresh and retry.',
    'module_not_found' => 'Module not found.',
];
