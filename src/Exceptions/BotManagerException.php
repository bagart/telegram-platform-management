<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Exceptions;

use BAGArt\TelegramBot\Contracts\Exceptions\TelegramBotException;

class BotManagerException extends \RuntimeException implements TelegramBotException
{
}
