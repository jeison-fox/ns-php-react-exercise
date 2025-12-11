<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/doctrine.php';

use App\Models\Transaction;
use App\Models\Category;

echo "Clearing existing data..." . PHP_EOL;

$em = createEntityManager();
$conn = $em->getConnection();

$conn->executeStatement("TRUNCATE TABLE transactions, categories RESTART IDENTITY CASCADE");

echo "Loading seed data from centralized JSON files..." . PHP_EOL;

$seedDataPath = __DIR__ . '/../data';
$categoriesData = json_decode(file_get_contents($seedDataPath . '/categories.json'), true);
$transactionsData = json_decode(file_get_contents($seedDataPath . '/transactions.json'), true);

echo "Seeding database with initial data..." . PHP_EOL;

$categories = [];

foreach ($categoriesData as $catName) {
    $category = new Category();
    $category->setName($catName);
    $em->persist($category);
    $categories[$catName] = $category;
}

$em->flush();

foreach ($transactionsData as $data) {
    $transaction = new Transaction();
    $transaction->setDescription($data['description']);
    $transaction->setAmount($data['amount']);
    $transaction->setType($data['type']);
    $transaction->setCategory($categories[$data['category']]);
    $transaction->setUserId($data['user_id']);
    $transaction->setDate(new DateTime($data['date']));
    $em->persist($transaction);
}

$em->flush();

echo "Database seeded successfully." . PHP_EOL;
echo "Seeded " . count($categoriesData) . " categories and " . count($transactionsData) . " transactions" . PHP_EOL;
