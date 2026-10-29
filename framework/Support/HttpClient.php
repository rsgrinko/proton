<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

use Closure;

/**
 * Запрос к чужому HTTP-сервису: почтовый сервис, вебхуки, любые интеграции.
 *
 *     $response = (new HttpClient(10))->post($url, $json, ['Content-Type: application/json']);
 *
 * curl не обязателен: расширения нет — запрос уходит потоками. Код ответа
 * разбирает вызывающий, исключение бросается только когда соединения не было
 * вовсе: «сервис ответил 500» и «сервис не ответил» — разные вещи.
 *
 * Прокси — вторым аргументом, одной строкой, и только так: переменные окружения
 * http_proxy/HTTPS_PROXY клиент не читает, без аргумента запрос идёт напрямую.
 *
 *
 *     new HttpClient(30, 'socks5://user:pass@10.0.0.5:1080');
 *     new HttpClient(30, 'http://proxy.local:3128');
 *
 * SOCKS5 есть только у curl: без расширения такой клиент бросит исключение
 * на первом запросе, а не уйдёт в сеть напрямую мимо прокси. Логин и пароль
 * со спецсимволами кодируются как в адресе (`@` — `%40`).
 *
 * Класс не закрыт от наследования нарочно: тесты, которым нужно подменить
 * саму реализацию, наследуются и переопределяют post()/get() — см.
 * WebhookTestClient в tests/WebhookTest.php. Обычно хватает fake():
 *
 *     HttpClient::fake(['*' => ['status' => 500, 'body' => 'упал']]);
 *
 *     // код, который где-то внутри делает (new HttpClient())->post(...)
 *
 *     assertSame($url, HttpClient::recorded()[0]['url']);
 *     HttpClient::reset();
 *
 * Заготовкой может быть и список ответов — они выдаются по очереди, последний
 * повторяется (повторы после 500, опрос статуса до готовности), — и функция,
 * которая собирает ответ по самому запросу:
 *
 *     HttpClient::fake([
 *         'https://api.example.com/orders/*' => [['status' => 500], ['status' => 200, 'body' => '{}']],
 *         'https://api.example.com/echo'     => fn (string $method, string $url, string $body) => ['body' => $body],
 *     ]);
 *
 * Проверку сертификата не отключаем. Там, где у PHP нет хранилища корневых
 * сертификатов (обычная история на Windows), путь к cacert.pem задаётся
 * настройкой HTTP_CA_BUNDLE — иначе любой https-запрос падает на «unable to
 * get local issuer certificate».
 *
 * Сервису со своим удостоверяющим центром, которого нет в общих наборах
 * (корень Минцифры у российских банков, внутренний УЦ компании), корневой
 * сертификат передаётся третьим аргументом — файлом из самого проекта:
 *
 *     new HttpClient(30, '', APP_ROOT . '/resources/certs/russian_trusted_root_ca.pem');
 *
 * Тогда этот клиент проверяет сервер только по нему, а HTTP_CA_BUNDLE
 * и системное хранилище не участвуют: ставить сертификат в ОС каждого сервера
 * не нужно, и он не начинает подтверждать чужие сайты для остального кода.
 */
class HttpClient
{
    /**
     * Заготовленные ответы по маске адреса (fnmatch) — null, если подмены нет.
     *
     * @var array<string, array{status?: int, body?: string}|array<int, array{status?: int, body?: string}>|Closure>|null
     */
    private static ?array $fakeResponses = null;

    /** @var array<string, int> Сколько ответов из списка по маске уже выдано */
    private static array $fakeCursor = [];

    /**
     * Что успели отправить, пока подмена была включена.
     *
     * @var array<int, array{method: string, url: string, body: string, headers: array<int, string>, proxy: string, ca_bundle: string}>
     */
    private static array $recorded = [];

    /** @var array{type: string, host: string, port: int, user: string, pass: string}|null Разобранный прокси */
    private ?array $proxyParts = null;

    public function __construct(private int $timeout = 10, private string $proxy = '', private string $caBundle = '')
    {
        $this->timeout  = max(1, $this->timeout);
        $this->proxy    = trim($this->proxy);
        $this->caBundle = trim($this->caBundle);

        // Нет файла — ошибка сразу: молча откатиться на общий набор значило бы
        // либо не достучаться до сервиса, либо проверять его не тем, что просили
        if ($this->caBundle !== '' && !is_file($this->caBundle)) {
            throw new ProtonException('Нет файла корневых сертификатов: ' . $this->caBundle);
        }

        // Разбираем сразу: опечатка в адресе прокси должна всплыть при сборке
        // клиента, а не тихо отправить запрос мимо прокси
        if ($this->proxy !== '') {
            $this->proxyParts = self::parseProxy($this->proxy);
        }
    }

    /**
     * Через какой прокси ходит клиент — пусто, если напрямую.
     */
    public function proxy(): string
    {
        return $this->proxy;
    }

    /**
     * По каким корневым сертификатам проверяется сервер: свой файл клиента,
     * иначе общий HTTP_CA_BUNDLE, иначе пусто — хранилище самого PHP.
     */
    public function caFile(): string
    {
        return $this->caBundle !== '' ? $this->caBundle : self::caBundle();
    }

    /**
     * Разбирает адрес прокси. Годятся только http и socks5: у curl есть и
     * другие типы, но потоки не умеют даже socks5, а разъезжаться по
     * возможностям двум веткам клиента незачем.
     *
     * @return array{type: string, host: string, port: int, user: string, pass: string}
     */
    public static function parseProxy(string $proxy): array
    {
        $parts  = parse_url($proxy);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($parts === false || !in_array($scheme, ['http', 'socks5'], true)) {
            throw new ProtonException('Прокси задаётся адресом http://… или socks5://…', ['proxy' => self::maskProxy($proxy)]);
        }

        if (($parts['host'] ?? '') === '' || ($parts['port'] ?? 0) <= 0) {
            throw new ProtonException('У прокси нужны и хост, и порт', ['proxy' => self::maskProxy($proxy)]);
        }

        return [
            'type' => $scheme,
            'host' => (string) $parts['host'],
            'port' => (int) $parts['port'],
            'user' => rawurldecode((string) ($parts['user'] ?? '')),
            'pass' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }

    /**
     * Адрес прокси без пароля — для сообщений и логов.
     */
    public static function maskProxy(string $proxy): string
    {
        return (string) preg_replace('#(//[^:/@]*):[^@/]*@#', '$1:***@', $proxy);
    }

    /**
     * Включает подмену: любой request() отсюда отвечает заготовкой, в сеть
     * никто не ходит. Пустая маска `''` — заглушка на всё подряд.
     *
     * @param array<string, array{status?: int, body?: string}|array<int, array{status?: int, body?: string}>|Closure> $responses
     */
    public static function fake(array $responses = []): void
    {
        self::$fakeResponses = $responses;
        self::$fakeCursor    = [];
        self::$recorded      = [];
    }

    /**
     * Выключает подмену и забывает записанные запросы — обязательно
     * после теста, иначе следующий тоже не пойдёт в сеть.
     */
    public static function reset(): void
    {
        self::$fakeResponses = null;
        self::$fakeCursor    = [];
        self::$recorded      = [];
    }

    /**
     * Что отправили, пока подмена была включена — самый свежий последним.
     *
     * @return array<int, array{method: string, url: string, body: string, headers: array<int, string>, proxy: string}>
     */
    public static function recorded(): array
    {
        return self::$recorded;
    }

    /**
     * Путь к набору корневых сертификатов из настроек. Пусто — полагаемся
     * на то, что PHP собран с рабочим хранилищем.
     */
    public static function caBundle(): string
    {
        $path = (string) Config::get('http.ca_bundle', '');

        return $path !== '' && is_file($path) ? $path : '';
    }

    /**
     * Проверять ли сертификат. Выключать это — значит отдать ключи интеграций
     * тому, кто встанет посередине; настройка есть только на случай, когда
     * разбираться некогда, а сервис свой.
     */
    public static function verifyPeer(): bool
    {
        return (bool) Config::get('http.verify_peer', true);
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, '', $headers);
    }

    /**
     * Возвращает код, тело и время ответа в миллисекундах — время уходит
     * в журнал доставок, по нему видно медленного подписчика.
     *
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    public function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        // Схему проверяем до подделки в тестах: запрет должен работать везде
        if (!self::allowedScheme($url)) {
            throw new ProtonException('Ходим только по http и https: ' . $url);
        }

        if (self::$fakeResponses !== null) {
            return $this->fakeRequest($method, $url, $body, $headers);
        }

        if (($this->proxyParts['type'] ?? '') === 'socks5' && !function_exists('curl_init')) {
            throw new ProtonException('SOCKS5-прокси работает только через curl, а расширения нет');
        }

        $started = microtime(true);

        $response = function_exists('curl_init')
            ? $this->viaCurl($method, $url, $body, $headers)
            : $this->viaStreams($method, $url, $body, $headers);

        $response['duration'] = (int) round((microtime(true) - $started) * 1000);

        return $response;
    }

    /**
     * Годится ли адрес для запроса: только http и https. curl умеет и file://,
     * и gopher://, и dict:// — адрес из панели (подписка вебхука) тогда читал
     * бы файлы сервера или слал сырые байты во внутренние сервисы.
     */
    public static function allowedScheme(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    private function fakeRequest(string $method, string $url, string $body, array $headers): array
    {
        $method = strtoupper($method);

        self::$recorded[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers, 'proxy' => $this->proxy, 'ca_bundle' => $this->caBundle];

        foreach (self::$fakeResponses as $pattern => $response) {
            $pattern = (string) $pattern;

            if ($pattern !== '' && $pattern !== '*' && !fnmatch($pattern, $url)) {
                continue;
            }

            if ($response instanceof Closure) {
                $response = $response($method, $url, $body, $headers);
            } elseif ($response !== [] && array_is_list($response)) {
                // Список: по ответу на запрос, последний повторяется — опрос
                // «пока не готово» не должен упираться в конец заготовки
                $index = self::$fakeCursor[$pattern] ?? 0;

                self::$fakeCursor[$pattern] = $index + 1;

                $response = $response[min($index, count($response) - 1)];
            }

            if (!is_array($response)) {
                throw new ProtonException('Заготовка ответа для «' . $pattern . '» — не массив');
            }

            return [
                'status'   => (int) ($response['status'] ?? 200),
                'body'     => (string) ($response['body'] ?? ''),
                'duration' => 0,
            ];
        }

        // Маска не нашлась — заготовки не спрашивали именно про этот адрес,
        // а не «сервис недоступен»: тесту нечего чинить в сети, если он сам
        // не позаботился о заготовке
        return ['status' => 200, 'body' => '', 'duration' => 0];
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    private function viaCurl(string $method, string $url, string $body, array $headers): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 5),
            // Переадресацию не ходим: подписчик должен дать рабочий адрес,
            // иначе подпись уйдёт туда, куда её не ждут
            CURLOPT_FOLLOWLOCATION => false,
            // Вторая стена после allowedScheme(): даже если проверку обойдут,
            // curl другие протоколы не откроет
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        if ($this->proxyParts !== null) {
            curl_setopt_array($curl, [
                CURLOPT_PROXY     => $this->proxyParts['host'] . ':' . $this->proxyParts['port'],
                // SOCKS5_HOSTNAME: имя сервиса резолвит прокси, а не мы — там,
                // где сервис заблокирован, обычно подменён и его DNS
                CURLOPT_PROXYTYPE => $this->proxyParts['type'] === 'socks5' ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP,
            ]);

            if ($this->proxyParts['user'] !== '') {
                curl_setopt($curl, CURLOPT_PROXYUSERPWD, $this->proxyParts['user'] . ':' . $this->proxyParts['pass']);
            }
        } else {
            // Без прокси — значит напрямую. Пустая строка у libcurl явно
            // выключает прокси: иначе он молча взял бы http_proxy/HTTPS_PROXY
            // из окружения процесса, и запрос во внутреннюю сеть ушёл бы
            // наружу (и повис бы там). Прокси задаётся только аргументом
            curl_setopt($curl, CURLOPT_PROXY, '');
        }

        $ca = $this->caFile();

        if ($ca !== '') {
            curl_setopt($curl, CURLOPT_CAINFO, $ca);
        }

        if (!self::verifyPeer()) {
            curl_setopt_array($curl, [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
        }

        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($curl);

        curl_close($curl);

        if ($result === false) {
            throw new ProtonException('Сервис недоступен: ' . ($error !== '' ? $error : $url));
        }

        return ['status' => $status, 'body' => (string) $result, 'duration' => 0];
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    private function viaStreams(string $method, string $url, string $body, array $headers): array
    {
        if ($this->proxyParts !== null) {
            // Сюда доходит только http-прокси: socks5 без curl отсечён в request()
            if ($this->proxyParts['user'] !== '') {
                $headers[] = 'Proxy-Authorization: Basic '
                    . base64_encode($this->proxyParts['user'] . ':' . $this->proxyParts['pass']);
            }
        }

        $options = [
            'http' => [
                'method'          => strtoupper($method),
                'header'          => implode("\r\n", $headers),
                'content'         => $body,
                'timeout'         => $this->timeout,
                'follow_location' => 0,
                // Нужен ответ, а не исключение: код разбираем сами
                'ignore_errors'   => true,
            ],
        ];

        if ($this->proxyParts !== null) {
            $options['http']['proxy'] = 'tcp://' . $this->proxyParts['host'] . ':' . $this->proxyParts['port'];

            // Полный адрес в строке запроса нужен прокси только для http:
            // https идёт туннелем через CONNECT, и внутри него — обычный путь
            $options['http']['request_fulluri'] = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http';
        }

        $ca = $this->caFile();

        if ($ca !== '') {
            $options['ssl']['cafile'] = $ca;
        }

        if (!self::verifyPeer()) {
            $options['ssl']['verify_peer']       = false;
            $options['ssl']['verify_peer_name']  = false;
            $options['ssl']['allow_self_signed'] = true;
        }

        $context = stream_context_create($options);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new ProtonException('Сервис недоступен: ' . $url);
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $result, 'duration' => 0];
    }
}
