<?php

// Работаем в корневой директории
chdir('../../');
require_once 'api/Simpla.php';
$simpla = new Simpla();

function sendRequest($url, $fields = [], $method = 'get', $config = [])
{
    $fields = http_build_query($fields);
    $_config = [
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.104 Safari/537.36',
        CURLOPT_COOKIEFILE => 'cookie.txt',
        CURLOPT_COOKIEJAR => 'cookie.txt',
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => '',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if ($method == 'post') {
        $_config[CURLOPT_POSTFIELDS] = $fields;
        $_config[CURLOPT_POST] = true;
    }
    foreach ($config as $key => $value) {
        $_config[$key] = $value;
    }
    $curl = curl_init();
    curl_setopt_array($curl, $_config);
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}

$rate = 1000000000000000000;

// Сумма, которую заплатил покупатель в Wei. Дробная часть отделяется точкой.
$amount = $_POST['amount'];

// Адрес получателя платежа
$receiver = $_POST['receiver'];

// Внутренний номер покупки продавца
// В этом поле передается id заказа в нашем магазине.
$order_id = intval($_POST['pInt']);

// В этом поле передается курс Ethereum к рублю при заказе.
$courseRUR = intval($_POST['pStr']);

// Адрес транзакции в блокчейн
$tx = $_POST['tx'];

// Контрольная сумма
$payHash = $_POST['payHash'];

////////////////////////////////////////////////
// Выберем заказ из базы
////////////////////////////////////////////////
$order = $simpla->orders->get_order(intval($order_id));
if (empty($order)) {
    die('Оплачиваемый заказ не найден');
}

////////////////////////////////////////////////
// Выбираем из базы соответствующий метод оплаты
////////////////////////////////////////////////
$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));
if (empty($method)) {
    die("Неизвестный метод оплаты");
}

$settings = unserialize($method->settings);

// Проверим статус транзакции
$etherscanUrl = 'https://api.etherscan.io/api?module=transaction&action=getstatus&txhash=' . $tx . '&apikey=' . $settings[apikey];
$result = sendRequest($etherscanUrl);
$status = json_decode($result, true);
if ($status['result']['isError'] !== 0) {
    ////////////////////////////////////////////////
    // Выберем заказ из базы
    ////////////////////////////////////////////////
    $order = $simpla->orders->get_order(intval($order_id));
    if (empty($order)) {
        die('Оплачиваемый заказ не найден');
    }

    // Нельзя оплатить уже оплаченный заказ
    if ($order->paid) {
        die('Этот заказ уже оплачен');
    }

    // Проверяем контрольную подпись
    $my_sign = md5($receiver . $amount);
    if ($payHash !== $my_sign) {
        die("bad sign\n");
    }

    // Стоимость заказа в рублях
    $amountRUR = round(($amount / $rate) * $courseRUR, 0); // 0 - не выводить копейки

    if ($amountRUR != $simpla->money->convert($order->total_price, $method->currency_id, false) || $amount <= 0) {
        die("incorrect price\n");
    }

    ////////////////////////////////////
    // Проверка наличия товара
    ////////////////////////////////////
    $purchases = $simpla->orders->get_purchases(['order_id' => intval($order->id)]);
    foreach ($purchases as $purchase) {
        $variant = $simpla->variants->get_variant(intval($purchase->variant_id));
        if (empty($variant) || (!$variant->infinity && $variant->stock < $purchase->amount)) {
            die("Нехватка товара $purchase->product_name $purchase->variant_name");
        }
    }

    // Установим статус оплачен
    $simpla->orders->update_order(intval($order->id), ['paid' => 1]);

    // Спишем товары
    $simpla->orders->close(intval($order->id));
    $simpla->notify->email_order_user(intval($order->id));
    $simpla->notify->email_order_admin(intval($order->id));

    // Перенаправим пользователя на страницу заказа
    header('Location: ' . $simpla->config->root_url . '/order/' . $order->url);
} else {
    echo "Транзакция не проверена, обновите страницу через несколько минут\n";
}