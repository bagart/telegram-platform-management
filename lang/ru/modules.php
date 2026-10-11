<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Активация модулей',
        'description' => 'Включайте и выключайте модули для бота. Изменения применяются к новым обновлениям сразу.',
        'select_bot' => 'Выберите бота',
        'no_bots' => 'Боты недоступны.',
        'no_modules' => 'Модули не зарегистрированы.',
        'column_module' => 'Модуль',
        'column_capabilities' => 'Возможности',
        'column_state' => 'Состояние',
        'state_enabled' => 'Включён',
        'state_disabled' => 'Выключен',
        'enable' => 'Включить',
        'disable' => 'Выключить',
    ],
    'enabled_flash' => 'Модуль ":module" включён для бота ":bot".',
    'disabled_flash' => 'Модуль ":module" выключен для бота ":bot".',
    'blocked' => 'Включение модуля заблокировано.',
    'concurrent_modification' => 'Состояние модуля изменилось параллельно; обновите страницу и повторите.',
    'module_not_found' => 'Модуль не найден.',
];
