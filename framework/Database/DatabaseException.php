<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Database;

use Rsgrinko\Proton\Support\ProtonException;

/**
 * Ошибка работы с базой: не подключились, не выполнили запрос, не накатили миграцию.
 */
final class DatabaseException extends ProtonException
{
}
