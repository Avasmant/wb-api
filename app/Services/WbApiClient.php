<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Тонкий клиент над тестовым WB API (109.73.206.144:6969).
 *
 * Эндпоинты (GET, авторизация через query-параметр key):
 *   /api/incomes ?dateFrom&dateTo&page&limit
 *   /api/orders  ?dateFrom&dateTo&page&limit
 *   /api/sales   ?dateFrom&dateTo&page&limit
 *   /api/stocks  ?dateFrom&page&limit            (dateFrom = только текущий день)
 *
 * Ответ — пагинированный JSON: { data: [...], links: {...}, meta: { current_page, last_page, total, ... } }
 */
class WbApiClient
{
    public function __construct(
        private string $base,
        private string $key,
        private int $timeout = 60,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            config('wb.base'),
            config('wb.key'),
            config('wb.timeout', 60),
        );
    }

    private function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->retry(
                times: 10,
                // backoff: уважаем Retry-After, иначе растущая пауза (до 30 с)
                sleepMilliseconds: function (int $attempt, $exception): int {
                    if ($exception instanceof RequestException && $exception->response) {
                        $retryAfter = (int) $exception->response->header('Retry-After');
                        if ($retryAfter > 0) {
                            return $retryAfter * 1000;
                        }
                    }
                    return min(30000, $attempt * 3000);
                },
                // ретраим только сетевые ошибки, троттлинг (429) и 5xx
                when: function ($exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    if ($exception instanceof RequestException && $exception->response) {
                        $status = $exception->response->status();
                        return $status === 429 || $status >= 500;
                    }
                    return false;
                },
                throw: true,
            )
            ->acceptJson();
    }

    /**
     * Загрузить одну страницу эндпоинта.
     *
     * @return array{data: array, meta: array}
     */
    public function page(string $endpoint, array $params, int $page, int $limit): array
    {
        $query = array_merge($params, [
            'page'  => $page,
            'limit' => $limit,
            'key'   => $this->key,
        ]);

        $response = $this->request()->get("{$this->base}/{$endpoint}", $query);
        $response->throw();

        $json = $response->json();

        return [
            'data' => $json['data'] ?? [],
            'meta' => $json['meta'] ?? [],
        ];
    }

    /**
     * Постранично пройти весь эндпоинт, отдавая страницы данных через генератор.
     * Память не растёт: за раз в памяти одна страница (<= limit записей).
     *
     * @return \Generator<int, array> массивы записей по страницам
     */
    public function paginate(string $endpoint, array $params, int $limit): \Generator
    {
        $page = 1;
        $delayMs = (int) config('wb.request_delay_ms', 250);

        do {
            $result = $this->page($endpoint, $params, $page, $limit);
            $rows = $result['data'];

            if (! empty($rows)) {
                yield $page => $rows;
            }

            $lastPage = (int) ($result['meta']['last_page'] ?? $page);
            $page++;

            // мягко притормаживаем, чтобы не упираться в rate limit
            if ($delayMs > 0 && $page <= $lastPage) {
                usleep($delayMs * 1000);
            }
        } while ($page <= $lastPage);
    }
}
