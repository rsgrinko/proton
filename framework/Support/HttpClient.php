<?php

declare(strict_types=1);

namespace Rsgrinko\Proton\Support;

/**
 * Запрос к чужому HTTP-сервису: почтовый сервис, вебхуки, любые интеграции.
 *
 *     $response = (new HttpClient(10))->post($url, $json, ['Content-Type: application/json']);
 *
 * curl не обязателен: расширения нет — запрос уходит потоками. Код ответа
 * разбирает вызывающий, исключение бросается только когда соединения не было
 * вовсе: «сервис ответил 500» и «сервис не ответил» — разные вещи.
 *
 * Класс не закрыт от наследования нарочно: тесты, которым нужно подменить
 * саму реализацию (проверить, что ушло, разными кодами ответа подряд),
 * наследуются и переопределяют post()/get() — см. WebhookTestClient
 * в tests/WebhookTest.php. Когда достаточно заранее заданных ответов —
 * fake() проще и не требует своего класса:
 *
 *     HttpClient::fake(['*' => ['status' => 500, 'body' => 'упал']]);
 *
 *     // код, который где-то внутри делает (new HttpClient())->post(...)
 *
 *     assertSame($url, HttpClient::recorded()[0]['url']);
 *     HttpClient::reset();
 *
 * Проверку сертификата не отключаем. Там, где у PHP нет хранилища корневых
 * сертификатов (обычная история на Windows), путь к cacert.pem задаётся
 * настройкой HTTP_CA_BUNDLE — иначе любой https-запрос падает на «unable to
 * get local issuer certificate».
 */
class HttpClient
{
    /**
     * Заготовленные ответы по маске адреса (fnmatch) — null, если подмены нет.
     *
     * @var array<string, array{status?: int, body?: string}>|null
     */
    private static ?array $fakeResponses = null;

    /**
     * Что успели отправить, пока подмена была включена.
     *
     * @var array<int, array{method: string, url: string, body: string, headers: array<int, string>}>
     */
    private static array $recorded = [];

    public function __construct(private int $timeout = 10)
    {
        $this->timeout = max(1, $this->timeout);
    }

    /**
     * Включает подмену: любой request() отсюда отвечает заготовкой, в сеть
     * никто не ходит. Пустая маска `''` — заглушка на всё подряд.
     *
     * @param array<string, array{status?: int, body?: string}> $responses
     */
    public static function fake(array $responses = []): void
    {
        self::$fakeResponses = $responses;
        self::$recorded      = [];
    }

    /**
     * Выключает подмену и забывает записанные запросы — обязательно
     * после теста, иначе следующий тоже не пойдёт в сеть.
     */
    public static function reset(): void
    {
        self::$fakeResponses = null;
        self::$recorded      = [];
    }

    /**
     * Что отправили, пока подмена была включена — самый свежий последним.
     *
     * @return array<int, array{method: string, url: string, body: string, headers: array<int, string>}>
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
        if (self::$fakeResponses !== null) {
            return $this->fakeRequest($method, $url, $body, $headers);
        }

        $started = microtime(true);

        $response = function_exists('curl_init')
            ? $this->viaCurl($method, $url, $body, $headers)
            : $this->viaStreams($method, $url, $body, $headers);

        $response['duration'] = (int) round((microtime(true) - $started) * 1000);

        return $response;
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, duration: int}
     */
    private function fakeRequest(string $method, string $url, string $body, array $headers): array
    {
        self::$recorded[] = ['method' => strtoupper($method), 'url' => $url, 'body' => $body, 'headers' => $headers];

        foreach (self::$fakeResponses as $pattern => $response) {
            if ($pattern === '' || $pattern === '*' || fnmatch($pattern, $url)) {
                return [
                    'status'   => (int) ($response['status'] ?? 200),
                    'body'     => (string) ($response['body'] ?? ''),
                    'duration' => 0,
                ];
            }
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
        ]);

        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $ca = self::caBundle();

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

        $ca = self::caBundle();

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
