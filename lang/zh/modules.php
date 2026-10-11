<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => '模块启用',
        'description' => '为机器人启用或禁用模块。更改会立即应用于新的更新。',
        'select_bot' => '选择机器人',
        'no_bots' => '没有可用的机器人。',
        'no_modules' => '未注册任何模块。',
        'column_module' => '模块',
        'column_capabilities' => '能力',
        'column_state' => '状态',
        'state_enabled' => '已启用',
        'state_disabled' => '已禁用',
        'enable' => '启用',
        'disable' => '禁用',
    ],
    'enabled_flash' => '模块 ":module" 已为机器人 ":bot" 启用。',
    'disabled_flash' => '模块 ":module" 已为机器人 ":bot" 禁用。',
    'blocked' => '模块启用被阻止。',
    'concurrent_modification' => '模块状态被并发修改；请刷新后重试。',
    'module_not_found' => '未找到模块。',
];
