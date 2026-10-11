<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Activation des modules',
        'description' => "Activez ou désactivez les modules d'un bot. Les changements s'appliquent immédiatement aux nouvelles mises à jour.",
        'select_bot' => 'Sélectionnez un bot',
        'no_bots' => 'Aucun bot disponible.',
        'no_modules' => 'Aucun module enregistré.',
        'column_module' => 'Module',
        'column_capabilities' => 'Capacités',
        'column_state' => 'État',
        'state_enabled' => 'Activé',
        'state_disabled' => 'Désactivé',
        'enable' => 'Activer',
        'disable' => 'Désactiver',
    ],
    'enabled_flash' => 'Le module ":module" est désormais activé pour le bot ":bot".',
    'disabled_flash' => 'Le module ":module" est désormais désactivé pour le bot ":bot".',
    'blocked' => "L'activation du module a été bloquée.",
    'concurrent_modification' => "L'état du module a changé en parallèle ; actualisez et réessayez.",
    'module_not_found' => 'Module introuvable.',
];
