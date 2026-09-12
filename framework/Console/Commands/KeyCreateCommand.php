<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Models\ApiToken;
use Rsgrinko\Proton\Models\User;

/**
 * выпустить ключ API.
 */
final class KeyCreateCommand extends Command
{
    public function name(): string
    {
        return 'key:create';
    }

    public function description(): string
    {
        return 'выпустить ключ доступа к API';
    }

    public function usage(): string
    {
        return 'key:create <название> --owner=<логин> [--ips=]';
    }

    public function run(): int
    {
        $name  = trim($this->arg(0));
        $login = (string) $this->option('owner', '');

        if ($login === '') {
            $this->fail('Укажите владельца: --owner=логин');

            return 1;
        }

        $user = User::findBy('login', $login);

        if ($user === null) {
            $this->fail('Пользователь не найден: ' . $login);

            return 1;
        }

        $issued = ApiToken::issue($name, $user->id(), (string) $this->option('ips', ''));

        $this->ok('Ключ выпущен для ' . $login);
        $this->line('');
        $this->line('  ' . $issued['key']);
        $this->line('');
        // В базе только хеш: показать ключ второй раз будет негде
        $this->line('  Сохраните его сейчас — больше он нигде не появится');

        return 0;
    }
}
