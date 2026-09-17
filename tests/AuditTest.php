<?php

declare(strict_types=1);

/**
 * Audit::between(): что попадает в «что изменилось» у записи в журнале.
 */

use Rsgrinko\Proton\Support\Audit;

test('audit: между: скалярное поле — было/стало', function (): void {
    $changes = Audit::between(['name' => 'Аудитор'], ['name' => 'Аудитор+']);

    assertSame(['Аудитор', 'Аудитор+'], $changes['name']);
});

test('audit: между: не изменившееся скалярное поле в разницу не попадает', function (): void {
    $changes = Audit::between(['name' => 'Аудитор'], ['name' => 'Аудитор']);

    assertSame([], $changes);
});

test('audit: между: список — сравнение по составу, а не как (string) массива', function (): void {
    // (string) [...] у PHP — всегда «Array»: до фикса смена состава прав
    // читалась бы как «ничего не изменилось»
    $changes = Audit::between(
        ['permissions' => ['users.view']],
        ['permissions' => ['users.view', 'users.manage']]
    );

    assertTrue(isset($changes['permissions']), 'смена состава списка должна попасть в разницу');
});

test('audit: между: список с тем же составом в другом порядке — не изменение', function (): void {
    $changes = Audit::between(
        ['permissions' => ['users.view', 'roles.view']],
        ['permissions' => ['roles.view', 'users.view']]
    );

    assertSame([], $changes, 'порядок элементов роли не должен считаться изменением');
});

test('audit: между: секретное поле маскируется, даже когда оно список', function (): void {
    $changes = Audit::between(['api_key' => ['a']], ['api_key' => ['b']]);

    assertSame(['скрыто', 'изменено'], $changes['api_key']);
});
