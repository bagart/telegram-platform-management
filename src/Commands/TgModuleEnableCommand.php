<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

/**
 * Platform/chat-scoped enablement toggle (0/1 exits); --bot writes the
 * bot-level default, --chat the chat override. For bot-scoped CAS
 * activation with 0/5 exit codes see tg:modules:enable (engine).
 */
class TgModuleEnableCommand extends TgModuleToggleCommand
{
    protected bool $enable = true;

    protected $signature = 'tg:module:enable
                            {module : Module id (see tg:modules:list)}
                            {--bot= : Bot id; omit for a platform-wide override}
                            {--chat= : Chat id; requires --bot, omit for the bot-level default}';

    protected $description = 'Enable a module platform-wide or for a bot/chat (for bot-scoped CAS see tg:modules:enable)';
}
