<?php

namespace App\Console\Commands;

use App\Models\Income;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Stock;
use App\Services\WbApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WbSyncCommand extends Command
{
    protected $signature = 'wb:sync
        {endpoint=all : incomes|orders|sales|stocks|all}
        {--from= : дата начала (Y-m-d); по умолчанию config(wb.default_date_from)}
        {--to= : дата конца (Y-m-d); по умолчанию сегодня}
        {--limit= : записей на страницу (по умолчанию config(wb.limit))}';

    protected $description = 'Стянуть данные WB API (incomes/orders/sales/stocks) и сохранить в БД (upsert).';

    private const ENDPOINTS = ['incomes', 'orders', 'sales', 'stocks'];

    public function handle(): int
    {
        $endpoint = strtolower($this->argument('endpoint'));
        $targets = $endpoint === 'all' ? self::ENDPOINTS : [$endpoint];

        foreach ($targets as $name) {
            if (! in_array($name, self::ENDPOINTS, true)) {
                $this->error("Неизвестный эндпоинт: {$name}");
                return self::FAILURE;
            }
        }

        $client = WbApiClient::fromConfig();
        $limit = (int) ($this->option('limit') ?: config('wb.limit', 500));
        $from = $this->option('from') ?: config('wb.default_date_from', '2020-01-01');
        $to = $this->option('to') ?: now()->format('Y-m-d');

        foreach ($targets as $name) {
            $this->syncEndpoint($client, $name, $from, $to, $limit);
        }

        return self::SUCCESS;
    }

    private function syncEndpoint(WbApiClient $client, string $name, string $from, string $to, int $limit): void
    {
        // stocks принимает только dateFrom и только текущий день
        $params = $name === 'stocks'
            ? ['dateFrom' => now()->format('Y-m-d')]
            : ['dateFrom' => $from, 'dateTo' => $to];

        $this->info("[{$name}] старт, params=" . json_encode($params, JSON_UNESCAPED_UNICODE));

        $total = 0;
        $pages = 0;

        $model = $this->modelFor($name);

        foreach ($client->paginate($name, $params, $limit) as $page => $rows) {
            $mapped = array_map(fn ($row) => $this->mapRow($name, $row), $rows);
            $updateColumns = $this->updateColumns($mapped);

            // Дробим страницу на под-батчи под лимит плейсхолдеров СУБД
            DB::transaction(function () use ($model, $mapped, $updateColumns) {
                foreach ($this->chunkBySqlLimit($mapped) as $batch) {
                    $model::upsert($batch, ['uniq_hash'], $updateColumns);
                }
            });

            $total += count($mapped);
            $pages++;
            $this->line("  [{$name}] страница {$page}: +" . count($mapped) . " (всего {$total})");
        }

        $this->info("[{$name}] готово: {$pages} стр., {$total} записей в БД.");
    }

    private function modelFor(string $name): string
    {
        return [
            'incomes' => Income::class,
            'orders'  => Order::class,
            'sales'   => Sale::class,
            'stocks'  => Stock::class,
        ][$name];
    }

    /** Колонки для обновления при конфликте uniq_hash (всё, кроме ключа и created_at). */
    private function updateColumns(array $mapped): array
    {
        $cols = array_keys($mapped[0] ?? []);
        return array_values(array_diff($cols, ['uniq_hash', 'created_at']));
    }

    /**
     * Разбить массив строк на под-батчи так, чтобы число плейсхолдеров
     * (строки × колонки) не превышало лимит драйвера СУБД.
     * MySQL допускает до 65535, SQLite — 999.
     */
    private function chunkBySqlLimit(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $cols = max(1, count($rows[0]));
        $budget = DB::connection()->getDriverName() === 'sqlite' ? 900 : 60000;
        $size = max(1, intdiv($budget, $cols));

        return array_chunk($rows, $size);
    }

    private function mapRow(string $name, array $r): array
    {
        $row = match ($name) {
            'incomes' => $this->mapIncome($r),
            'orders'  => $this->mapOrder($r),
            'sales'   => $this->mapSale($r),
            'stocks'  => $this->mapStock($r),
        };

        $now = now();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $row;
    }

    private function mapIncome(array $r): array
    {
        return [
            'income_id'        => $this->int($r['income_id'] ?? null),
            'number'           => $this->str($r['number'] ?? null),
            'date'             => $this->date($r['date'] ?? null),
            'last_change_date' => $this->date($r['last_change_date'] ?? null),
            'supplier_article' => $this->str($r['supplier_article'] ?? null),
            'tech_size'        => $this->str($r['tech_size'] ?? null),
            'barcode'          => $this->str($r['barcode'] ?? null),
            'quantity'         => $this->int($r['quantity'] ?? null),
            'total_price'      => $this->dec($r['total_price'] ?? null),
            'date_close'       => $this->date($r['date_close'] ?? null),
            'warehouse_name'   => $this->str($r['warehouse_name'] ?? null),
            'nm_id'            => $this->int($r['nm_id'] ?? null),
            'uniq_hash'        => $this->hash([
                $r['income_id'] ?? '', $r['nm_id'] ?? '', $r['barcode'] ?? '',
                $r['tech_size'] ?? '', $r['date'] ?? '', $r['warehouse_name'] ?? '',
            ]),
        ];
    }

    private function mapOrder(array $r): array
    {
        return [
            'g_number'         => $this->str($r['g_number'] ?? null),
            'date'             => $this->datetime($r['date'] ?? null),
            'last_change_date' => $this->date($r['last_change_date'] ?? null),
            'supplier_article' => $this->str($r['supplier_article'] ?? null),
            'tech_size'        => $this->str($r['tech_size'] ?? null),
            'barcode'          => $this->str($r['barcode'] ?? null),
            'total_price'      => $this->dec($r['total_price'] ?? null),
            'discount_percent' => $this->int($r['discount_percent'] ?? null),
            'warehouse_name'   => $this->str($r['warehouse_name'] ?? null),
            'oblast'           => $this->str($r['oblast'] ?? null),
            'income_id'        => $this->int($r['income_id'] ?? null),
            'odid'             => $this->str($r['odid'] ?? null),
            'nm_id'            => $this->int($r['nm_id'] ?? null),
            'subject'          => $this->str($r['subject'] ?? null),
            'category'         => $this->str($r['category'] ?? null),
            'brand'            => $this->str($r['brand'] ?? null),
            'is_cancel'        => $this->bool($r['is_cancel'] ?? null),
            'cancel_dt'        => $this->datetime($r['cancel_dt'] ?? null),
            'uniq_hash'        => $this->hash([
                $r['g_number'] ?? '', $r['odid'] ?? '', $r['nm_id'] ?? '',
                $r['barcode'] ?? '', $r['date'] ?? '',
            ]),
        ];
    }

    private function mapSale(array $r): array
    {
        return [
            'g_number'           => $this->str($r['g_number'] ?? null),
            'date'               => $this->date($r['date'] ?? null),
            'last_change_date'   => $this->date($r['last_change_date'] ?? null),
            'supplier_article'   => $this->str($r['supplier_article'] ?? null),
            'tech_size'          => $this->str($r['tech_size'] ?? null),
            'barcode'            => $this->str($r['barcode'] ?? null),
            'total_price'        => $this->dec($r['total_price'] ?? null),
            'discount_percent'   => $this->int($r['discount_percent'] ?? null),
            'is_supply'          => $this->bool($r['is_supply'] ?? null),
            'is_realization'     => $this->bool($r['is_realization'] ?? null),
            'promo_code_discount'=> $this->dec($r['promo_code_discount'] ?? null),
            'warehouse_name'     => $this->str($r['warehouse_name'] ?? null),
            'country_name'       => $this->str($r['country_name'] ?? null),
            'oblast_okrug_name'  => $this->str($r['oblast_okrug_name'] ?? null),
            'region_name'        => $this->str($r['region_name'] ?? null),
            'income_id'          => $this->int($r['income_id'] ?? null),
            'sale_id'            => $this->str($r['sale_id'] ?? null),
            'odid'               => $this->str($r['odid'] ?? null),
            'spp'                => $this->dec($r['spp'] ?? null),
            'for_pay'            => $this->dec($r['for_pay'] ?? null),
            'finished_price'     => $this->dec($r['finished_price'] ?? null),
            'price_with_disc'    => $this->dec($r['price_with_disc'] ?? null),
            'nm_id'              => $this->int($r['nm_id'] ?? null),
            'subject'            => $this->str($r['subject'] ?? null),
            'category'           => $this->str($r['category'] ?? null),
            'brand'              => $this->str($r['brand'] ?? null),
            'is_storno'          => $this->bool($r['is_storno'] ?? null),
            'uniq_hash'          => $this->hash([
                // sale_id уникален; запасной ключ — если sale_id пуст
                ($r['sale_id'] ?? '') !== ''
                    ? $r['sale_id']
                    : implode('|', [$r['g_number'] ?? '', $r['date'] ?? '', $r['barcode'] ?? '', $r['nm_id'] ?? '']),
            ]),
        ];
    }

    private function mapStock(array $r): array
    {
        return [
            'date'               => $this->date($r['date'] ?? null),
            'last_change_date'   => $this->date($r['last_change_date'] ?? null),
            'supplier_article'   => $this->str($r['supplier_article'] ?? null),
            'tech_size'          => $this->str($r['tech_size'] ?? null),
            'barcode'            => $this->str($r['barcode'] ?? null),
            'quantity'           => $this->int($r['quantity'] ?? null),
            'is_supply'          => $this->bool($r['is_supply'] ?? null),
            'is_realization'     => $this->bool($r['is_realization'] ?? null),
            'quantity_full'      => $this->int($r['quantity_full'] ?? null),
            'warehouse_name'     => $this->str($r['warehouse_name'] ?? null),
            'in_way_to_client'   => $this->int($r['in_way_to_client'] ?? null),
            'in_way_from_client' => $this->int($r['in_way_from_client'] ?? null),
            'nm_id'              => $this->int($r['nm_id'] ?? null),
            'subject'            => $this->str($r['subject'] ?? null),
            'category'           => $this->str($r['category'] ?? null),
            'brand'              => $this->str($r['brand'] ?? null),
            'sc_code'            => $this->str($r['sc_code'] ?? null),
            'price'              => $this->dec($r['price'] ?? null),
            'discount'           => $this->dec($r['discount'] ?? null),
            'uniq_hash'          => $this->hash([
                $r['date'] ?? '', $r['barcode'] ?? '', $r['nm_id'] ?? '',
                $r['tech_size'] ?? '', $r['warehouse_name'] ?? '',
            ]),
        ];
    }

    // ---- нормализация значений ----

    private function hash(array $parts): string
    {
        return md5(implode('|', array_map(fn ($p) => (string) $p, $parts)));
    }

    private function str($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function int($v): ?int
    {
        if ($v === null || $v === '') return null;
        return (int) $v;
    }

    private function dec($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (! is_numeric($v)) return null;
        return (string) $v;
    }

    private function bool($v): ?bool
    {
        if ($v === null || $v === '') return null;
        if (is_bool($v)) return $v;
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function date($v): ?string
    {
        $v = $this->str($v);
        if ($v === null) return null;
        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function datetime($v): ?string
    {
        $v = $this->str($v);
        if ($v === null) return null;
        try {
            return Carbon::parse($v)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
