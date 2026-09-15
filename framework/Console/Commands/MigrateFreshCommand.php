<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Console\Commands;

use Rsgrinko\Proton\Console\Command;
use Rsgrinko\Proton\Database\Connection;
use Rsgrinko\Proton\Database\Migrator;
use Rsgrinko\Proton\Database\Schema\Builder;
use Rsgrinko\Proton\Support\Config;

/**
 * пересобрать схему с нуля: снести все таблицы и накатить миграции заново.
 *
 * Это инструмент разработки. На боевой базе он уничтожает данные, поэтому
 * при APP_ENV=production команда не работает вовсе, а в остальных случаях
 * требует подтверждения или --force.
 */
final class MigrateFreshCommand extends Command
{
    public function name(): string
    {
        return 'migrate:fresh';
    }

    public function description(): string
    {
        return 'снести таблицы и накатить миграции заново (разработка)';
    }

    public function usage(): string
    {
        return 'migrate:fresh [--seed] [--force]';
    }

    public function run(): int
    {
        $environment = (string) Config::get('app.env', 'production');

        if ($environment === 'production') {
            $this->fail('На боевом окружении команда не работает: она удаляет все данные');

            return 1;
        }

        $db     = Connection::instance();
        $tables = $db->tables();

        if ($tables !== [] && !$this->confirm('Будут удалены все таблицы (' . count($tables) . ') и данные в них. Продолжать?')) {
            $this->line('Отменено');

            return 0;
        }

        $builder = new Builder($db);

        // Порядок не важен: внешних ключей ядро не заводит, а если их добавило
        // приложение, снимаем проверку на время сноса
        $this->withoutForeignKeys($db, static function () use ($builder, $tables): void {
            foreach ($tables as $table) {
                $builder->drop($table);
            }
        });

        $this->ok('Удалено таблиц: ' . count($tables));

        $applied = (new Migrator())->run();

        $this->ok('Применено миграций: ' . count($applied));

        if ($this->hasOption('seed')) {
            $this->line('');

            return (new SeedCommand())->withInput([], $this->options)->run();
        }

        return 0;
    }

    /**
     * Снос таблиц без проверки внешних ключей.
     */
    private function withoutForeignKeys(Connection $db, callable $body): void
    {
        $sqlite = $db->isSqlite();

        $db->execute($sqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');

        try {
            $body();
        } finally {
            $db->execute($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
