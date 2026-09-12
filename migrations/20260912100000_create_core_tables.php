<?php

declare(strict_types=1);

namespace App\Migrations;

use Rsgrinko\Proton\Access\Permission;
use Rsgrinko\Proton\Database\Migration;
use Rsgrinko\Proton\Database\Schema\Blueprint;

/**
 * Таблицы ядра: пользователи и роли, сеансы, ключи API, журнал действий,
 * настройки, счётчики, кэш и очередь задач.
 */
final class CreateCoreTables extends Migration
{
    public function up(): void
    {
        $this->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 255)->default('');
            $table->text('permissions')->nullable();
            $table->integer('is_system')->default(0);
            $table->timestamps();

            $table->unique('idx_roles_name', 'name');
        });

        $this->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('login', 100);
            $table->string('email', 191)->default('');
            $table->string('name', 191)->default('');
            $table->string('password_hash', 255);
            $table->foreignId('role_id');
            $table->integer('active')->default(1);
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 64)->default('');
            // Отметка «все сеансы до этого времени недействительны»:
            // «выйти на остальных устройствах» и смена пароля
            $table->dateTime('sessions_from')->nullable();
            $table->timestamps();

            $table->unique('idx_users_login', 'login');
            $table->index('idx_users_email', 'email');
        });

        $this->create('remember_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('selector', 32);
            $table->string('token_hash', 64);
            // Прежний секрет живёт минуту: соседние запросы той же страницы
            // приходят со старой кукой, и без этого окна их считали бы кражей
            $table->string('previous_hash', 64)->default('');
            $table->dateTime('rotated_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('ip', 64)->default('');
            $table->string('user_agent', 255)->default('');

            $table->unique('idx_remember_selector', 'selector');
        });

        $this->create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('sid', 64);
            $table->string('remember_selector', 32)->default('');
            $table->string('ip', 64)->default('');
            $table->string('user_agent', 255)->default('');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();

            $table->unique('idx_sessions_sid', 'sid');
        });

        $this->create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191)->default('');
            $table->foreignId('user_id');
            $table->string('prefix', 32);
            $table->string('token_hash', 64);
            $table->string('allowed_ips', 500)->default('');
            $table->integer('active')->default(1);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 64)->default('');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('idx_tokens_prefix', 'prefix');
        });

        $this->create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            // Логин строкой: пользователя могут удалить, а журнал должен остаться читаемым
            $table->string('user_login', 100)->default('');
            $table->string('action', 32);
            $table->string('entity', 64)->default('');
            $table->string('entity_id', 64)->default('');
            $table->string('description', 500)->default('');
            $table->text('changes')->nullable();
            $table->string('ip', 64)->default('');
            $table->dateTime('created_at');

            $table->index('idx_audit_created', 'created_at');
            $table->index('idx_audit_entity', ['entity', 'entity_id']);
        });

        $this->create('settings', function (Blueprint $table): void {
            $table->string('setting_key', 191)->primary();
            $table->text('value')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        $this->create('counters', function (Blueprint $table): void {
            $table->string('counter_key', 191)->primary();
            $table->integer('value')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        $this->create('cache', function (Blueprint $table): void {
            $table->string('cache_key', 191)->primary();
            $table->longText('value')->nullable();
            $table->dateTime('expires_at');
        });

        $this->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 64)->default('default');
            $table->string('job_class', 191);
            $table->longText('payload')->nullable();
            $table->string('status', 16)->default('queued');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->dateTime('available_at');
            $table->dateTime('reserved_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->string('request_id', 64)->default('');
            $table->timestamps();

            // По этому индексу воркер и выбирает следующую задачу
            $table->index('idx_jobs_pick', ['status', 'queue', 'available_at']);
        });

        $this->seedRoles();
    }

    public function down(): void
    {
        foreach (['jobs', 'cache', 'counters', 'settings', 'audit_log', 'api_tokens', 'user_sessions', 'remember_tokens', 'users', 'roles'] as $table) {
            $this->drop($table);
        }
    }

    /**
     * Две роли на старте: администратор и обычный пользователь.
     *
     * Права администратора в базе не храним — у встроенной роли они берутся
     * из кода, иначе новый раздел оказался бы ему недоступен. Повторный накат
     * ничего не задваивает: сначала смотрим, нет ли уже такой роли.
     */
    private function seedRoles(): void
    {
        $rows = [
            ['Администратор', 'Полный доступ ко всему', json_encode(Permission::admin(), JSON_UNESCAPED_UNICODE), 1],
            ['Пользователь', 'Свои записи, без управления сервисом', json_encode(Permission::user(), JSON_UNESCAPED_UNICODE), 0],
        ];

        foreach ($rows as [$name, $description, $permissions, $system]) {
            // В режиме «на словах» таблицы ещё нет — читать из неё нечего,
            // запросы просто печатаются
            $exists = $this->pretending()
                ? null
                : $this->db()->selectOne('SELECT id FROM roles WHERE name = :name', ['name' => $name]);

            if ($exists !== null) {
                continue;
            }

            $this->statement(
                'INSERT INTO roles (name, description, permissions, is_system, created_at, updated_at)
                 VALUES (:name, :description, :permissions, :is_system, :created_at, :updated_at)',
                [
                    'name'        => $name,
                    'description' => $description,
                    'permissions' => $permissions,
                    'is_system'   => $system,
                    'created_at'  => $this->now(),
                    'updated_at'  => $this->now(),
                ]
            );
        }
    }
}
