<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Rsgrinko\Proton\Http\Controller;
use Rsgrinko\Proton\Http\Request;
use Rsgrinko\Proton\Http\Response;
use Rsgrinko\Proton\Support\Validator;

/**
 * Основа контроллеров API: единый формат ответа.
 *
 * Успех — {"data": …}, ошибка — {"error": {"message": …, "status": …}}.
 * Клиенту не приходится гадать, что он получил.
 */
abstract class ApiController extends Controller
{
    /**
     * @param array<string, mixed>|array<int, mixed> $data
     * @param array<string, mixed>                   $meta
     */
    protected function data(array $data, int $status = 200, array $meta = []): Response
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return Response::json($payload, $status);
    }

    /**
     * Страница списка вместе со счётчиками.
     *
     * @param array{items: array<int, mixed>, total: int, page: int, pages: int, per_page: int} $page
     * @param callable(mixed): array<string, mixed> $map как превратить запись в ответ
     */
    protected function paginated(array $page, callable $map): Response
    {
        return $this->data(
            array_map($map, $page['items']),
            200,
            ['total' => $page['total'], 'page' => $page['page'], 'pages' => $page['pages'], 'per_page' => $page['per_page']]
        );
    }

    /**
     * Разбор тела запроса с понятной ошибкой на сломанном JSON: «пустое тело»
     * и «опечатка в кавычке» — разные беды, и искать их надо в разных местах.
     *
     * @param array<string, string> $rules
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request, array $rules): array
    {
        $error = $request->bodyError();

        if ($error !== null) {
            throw new \Rsgrinko\Proton\Support\ProtonException($error, [], 400);
        }

        return Validator::make($request->all(), $rules)->validate();
    }
}
