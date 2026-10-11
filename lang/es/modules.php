<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Activación de módulos',
        'description' => 'Activa o desactiva módulos para un bot. Los cambios se aplican a las actualizaciones nuevas de inmediato.',
        'select_bot' => 'Selecciona un bot',
        'no_bots' => 'No hay bots disponibles.',
        'no_modules' => 'No hay módulos registrados.',
        'column_module' => 'Módulo',
        'column_capabilities' => 'Capacidades',
        'column_state' => 'Estado',
        'state_enabled' => 'Activado',
        'state_disabled' => 'Desactivado',
        'enable' => 'Activar',
        'disable' => 'Desactivar',
    ],
    'enabled_flash' => 'El módulo ":module" ahora está activado para el bot ":bot".',
    'disabled_flash' => 'El módulo ":module" ahora está desactivado para el bot ":bot".',
    'blocked' => 'La activación del módulo fue bloqueada.',
    'concurrent_modification' => 'El estado del módulo cambió de forma concurrente; actualiza e inténtalo de nuevo.',
    'module_not_found' => 'Módulo no encontrado.',
];
