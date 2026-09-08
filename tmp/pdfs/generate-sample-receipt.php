<?php

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__, 2) . '/includes/class-tuma-pdf-receipt.php';

$pdf = Tuma_PDF_Receipt::generate(array(
    'store_name' => 'Tuma Demo Store',
    'store_url' => 'https://shop.example.test',
    'order_number' => 'WC-10428',
    'customer_name' => 'Amina Wanjiku',
    'customer_email' => 'amina@example.test',
    'date' => '08 Sep 2026, 10:42',
    'currency' => 'KES',
    'subtotal' => 5225,
    'shipping' => 250,
    'total' => 5475,
    'payment_method' => 'M-Pesa via Tuma Payments',
    'transaction_id' => 'TUMA8K4M2P',
    'items' => array(
        array('name' => 'Classic T-shirt - Black / Medium', 'quantity' => 2, 'unit_price' => 1200, 'total' => 2400),
        array('name' => 'Canvas Travel Bag', 'quantity' => 1, 'unit_price' => 2450, 'total' => 2450),
        array('name' => 'Reusable Water Bottle', 'quantity' => 1, 'unit_price' => 375, 'total' => 375),
    ),
));

file_put_contents(dirname(__DIR__, 2) . '/output/pdf/sample-paid-order-receipt.pdf', $pdf);
