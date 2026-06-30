<?php

return [
    // Базовый URL API (без завершающего слэша), напр. http://109.73.206.144:6969/api
    'base' => rtrim(env('WB_API_BASE', 'http://109.73.206.144:6969/api'), '/'),

    // Секретный токен, передаётся в query-параметре key
    'key' => env('WB_API_KEY', ''),

    // Кол-во записей на страницу (макс. 500 по документации)
    'limit' => (int) env('WB_API_LIMIT', 500),

    // Дата, начиная с которой тянуть данные по умолчанию (для orders/sales/incomes)
    'default_date_from' => env('WB_API_DATE_FROM', '2020-01-01'),

    // Таймаут HTTP-запроса, сек
    'timeout' => (int) env('WB_API_TIMEOUT', 60),
];
