<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\ApiToken;

/**
 * отключить ключ API.
 */
final class KeyRevokeCommand extends Command
{
    public function name(): string
    {
        return 'key:revoke';
    }

    public function description(): string
    {
        return 'отключить ключ доступа';
    }

    public function usage(): string
    {
        return 'key:revoke <id>';
    }

    public function run(): int
    {
        $token = ApiToken::find((int) $this->arg(0));

        if ($token === null) {
            $this->fail('Ключ не найден');

            return 1;
        }

        // Не удаляем, а отключаем: строка нужна, чтобы понимать, чем ходили раньше
        $token->forceFill(['active' => 0])->save();

        $this->ok('Ключ ' . $token->mask() . ' отключён');

        return 0;
    }
}
