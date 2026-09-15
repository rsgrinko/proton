<?php

declare(strict_types=1);

/**
 * Фильтры и сортировка списков: что приходит из адреса, то и уходит в запрос —
 * но только после проверки.
 */

use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Models\User;
use Rsgrinko\Proton\Support\Filter;
use Rsgrinko\Proton\Support\Filters;

/**
 * Фильтры по запросу с готовыми параметрами адреса.
 *
 * @param array<string, mixed> $query
 * @param array<int, Filter>   $fields
 */
function filtersFor(array $query, array $fields): Filters
{
    return Filters::make(Request::create('GET', '/admin/users', [], $query), $fields);
}

test('фильтры: поиск идёт по всем колонкам сразу', function (): void {
    $filters = filtersFor(['q' => 'иван'], [Filter::search('q', 'Поиск', ['login', 'email'])]);

    $sql = $filters->apply(User::query())->toSql();

    assertContains('login LIKE', $sql);
    assertContains('OR', $sql);
    assertContains('email LIKE', $sql);
    assertSame('иван', $filters->value('q'));
});

test('фильтры: значение не из списка отбрасывается', function (): void {
    $fields = [Filter::select('role_id', 'Роль', [1 => 'админ', 2 => 'гость'])];

    assertSame('2', filtersFor(['role_id' => '2'], $fields)->value('role_id'));

    $чужое = filtersFor(['role_id' => '1 OR 1=1'], $fields);

    assertSame('', $чужое->value('role_id'));
    assertNotContains('role_id', $чужое->apply(User::query())->toSql());
});

test('фильтры: сортировать можно только по разрешённым колонкам', function (): void {
    $fields = [Filter::search('q', 'Поиск', ['login'])];

    $request = Request::create('GET', '/admin/users', [], ['sort' => 'login', 'dir' => 'asc']);
    $filters = Filters::make($request, $fields)->sortable(['id', 'login'], 'id');

    assertSame('login', $filters->sort());
    assertSame('asc', $filters->direction());
    assertContains('ORDER BY', $filters->apply(User::query())->toSql());

    // Чужая колонка в ORDER BY не попадает — остаётся та, что по умолчанию
    $request = Request::create('GET', '/admin/users', [], ['sort' => 'password_hash']);
    $filters = Filters::make($request, $fields)->sortable(['id', 'login'], 'id');

    assertSame('id', $filters->sort());
    assertNotContains('password_hash', $filters->apply(User::query())->toSql());
});

test('фильтры: диапазон дат берёт день целиком', function (): void {
    $filters = filtersFor(
        ['when_from' => '2026-09-01', 'when_to' => '2026-09-10'],
        [Filter::dates('when', 'Когда', 'created_at')]
    );

    $query = $filters->apply(User::query());

    assertContains('created_at >=', $query->toSql());
    assertContains('created_at <=', $query->toSql());
    assertContains('2026-09-01 00:00:00', implode('|', array_map('strval', $query->bindings())));
    assertContains('2026-09-10 23:59:59', implode('|', array_map('strval', $query->bindings())));

    // Дата, которой не бывает, до запроса не доходит
    $кривая = filtersFor(['when_from' => 'вчера-позавчера'], [Filter::dates('when', 'Когда', 'created_at')]);

    assertSame('', $кривая->value('when_from'));
    assertNotContains('created_at', $кривая->apply(User::query())->toSql());
});

test('фильтры: «заполнено» — это NULL или не NULL', function (): void {
    $fields = [Filter::filled('read', 'Прочитано', 'да', 'нет', 'read_at')];

    assertContains('read_at IS NULL', filtersFor(['read' => '0'], $fields)->apply(User::query())->toSql());
    assertContains('read_at IS NOT NULL', filtersFor(['read' => '1'], $fields)->apply(User::query())->toSql());
});

test('фильтры: непустые значения тащатся по страницам', function (): void {
    $request = Request::create('GET', '/admin/users', [], ['q' => 'иван', 'active' => '', 'sort' => 'login', 'dir' => 'asc']);

    $filters = Filters::make($request, [
        Filter::search('q', 'Поиск', ['login']),
        Filter::flag('active', 'Активен'),
    ])->sortable(['id', 'login'], 'id');

    assertSame(['q' => 'иван', 'sort' => 'login', 'dir' => 'asc'], $filters->params());
    assertTrue($filters->active());
    assertFalse(Filters::make(Request::create('GET', '/admin/users'), [Filter::search('q', 'Поиск', ['login'])])->active());
});
