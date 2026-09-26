<?php

declare(strict_types=1);

/**
 * Проверка входных данных.
 */

use Rsgrinko\Proton\Support\ValidationException;
use Rsgrinko\Proton\Support\Validator;

test('валидатор: обязательные поля и понятные сообщения', function (): void {
    $error = assertThrows(static function (): void {
        Validator::make([], ['email' => 'required|email'], ['email' => 'Почта'])->validate();
    });

    assertTrue($error instanceof ValidationException);
    assertContains('Почта', $error->first());
});

test('валидатор: собирает все ошибки сразу, а не по одной', function (): void {
    try {
        Validator::make(['login' => 'ab'], ['login' => 'required|min:3', 'email' => 'required|email'])->validate();

        assertTrue(false, 'должно было упасть');
    } catch (ValidationException $e) {
        assertCount(2, $e->errors(), 'ошибок должно быть две — по одной на поле');
    }
});

test('валидатор: возвращает только проверенные поля', function (): void {
    $clean = Validator::make(
        ['title' => ' Заголовок ', 'лишнее' => 'не нужно'],
        ['title' => 'required|max:191']
    )->validate();

    assertSame(['title' => 'Заголовок'], $clean, 'лишнее не проходит, строки обрезаются');
});

test('валидатор: приводит типы к объявленным', function (): void {
    $clean = Validator::make(
        ['age' => '42', 'price' => '3.5', 'active' => 'on'],
        ['age' => 'integer', 'price' => 'numeric', 'active' => 'boolean']
    )->validate();

    assertSame(42, $clean['age']);
    assertSame(3.5, $clean['price']);
    assertSame(1, $clean['active']);
});

test('валидатор: снятая галочка — это ноль, а не NULL', function (): void {
    // Браузер не присылает снятую галочку вовсе, и NULL уезжал бы в NOT NULL
    $clean = Validator::make([], ['pinned' => 'nullable|boolean'])->validate();

    assertSame(0, $clean['pinned']);
});

test('валидатор: пустое необязательное поле не ругается на формат', function (): void {
    $clean = Validator::make(['email' => ''], ['email' => 'nullable|email'])->validate();

    assertNull($clean['email']);
});

test('валидатор: повтор пароля и совпадение полей', function (): void {
    assertThrows(static function (): void {
        Validator::make(['password' => 'секрет123', 'password_confirmation' => 'другое'], ['password' => 'required|confirmed'])->validate();
    });

    $clean = Validator::make(
        ['password' => 'секрет123', 'password_confirmation' => 'секрет123'],
        ['password' => 'required|confirmed']
    )->validate();

    assertSame('секрет123', $clean['password']);
});

test('валидатор: unique смотрит в базу и не спотыкается о свою запись', function (): void {
    $user = Rsgrinko\Proton\Models\User::register('unique_test_' . bin2hex(random_bytes(3)), 'секрет123', [
        'email' => 'unique' . bin2hex(random_bytes(3)) . '@example.com',
    ]);

    afterTests(static function () use ($user): void {
        $user->forceDelete();
    });

    assertThrows(static function () use ($user): void {
        Validator::make(['login' => (string) $user->login], ['login' => 'required|unique:users,login'])->validate();
    }, 'занятый логин должен отвергаться');

    // Своя же запись при правке помехой быть не должна
    $clean = Validator::make(
        ['login' => (string) $user->login],
        ['login' => 'required|unique:users,login,' . $user->id()]
    )->validate();

    assertSame((string) $user->login, $clean['login']);
});

test('валидатор: адрес почты проверяется по-настоящему', function (): void {
    assertTrue(Validator::isEmail('user@example.com'));
    assertFalse(Validator::isEmail('user@localhost'), 'домен без точки не годится');
    assertFalse(Validator::isEmail('без-собаки'));
    assertFalse(Validator::isEmail(''));
});

test('валидатор: ссылка — только http и https', function (): void {
    foreach (['https://example.com/hook', 'http://127.0.0.1:8080/x'] as $url) {
        assertTrue(Validator::make(['url' => $url], ['url' => 'url'])->passes(), 'должна пройти: ' . $url);
    }

    // FILTER_VALIDATE_URL такие пропускает, а за ними чтение файлов сервера
    foreach (['file://localhost/etc/passwd', 'gopher://127.0.0.1:6379/_x', 'javascript://x/%0aalert(1)'] as $url) {
        assertFalse(Validator::make(['url' => $url], ['url' => 'url'])->passes(), 'не должна пройти: ' . $url);
    }
});
