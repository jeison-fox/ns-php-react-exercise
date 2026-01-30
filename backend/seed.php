<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/doctrine.php';

use App\Models\Transaction;
use App\Models\Category;
use App\Models\Tag;

$em = createEntityManager();
$conn = $em->getConnection();

echo "Ensuring database schema exists..." . PHP_EOL;
$schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
$classes = [
    $em->getClassMetadata(Category::class),
    $em->getClassMetadata(Tag::class),
    $em->getClassMetadata(Transaction::class),
];

try {
    $schemaTool->updateSchema($classes);
    echo "Schema updated." . PHP_EOL;
} catch (\Exception $e) {
    echo "Schema update note: " . $e->getMessage() . PHP_EOL;
}

echo "Clearing existing data..." . PHP_EOL;
$conn->executeStatement("TRUNCATE TABLE transaction_tags, transactions, tags, categories RESTART IDENTITY CASCADE");

echo "Loading seed data from centralized JSON files..." . PHP_EOL;

$seedDataPath = __DIR__ . '/../data';
$categoriesData = json_decode(file_get_contents($seedDataPath . '/categories.json'), true);
$transactionsData = json_decode(file_get_contents($seedDataPath . '/transactions.json'), true);
$tagsData = json_decode(file_get_contents($seedDataPath . '/tags.json'), true);

echo "Seeding database with initial data..." . PHP_EOL;

$categories = [];
foreach ($categoriesData as $catName) {
    $category = new Category();
    $category->setName($catName);
    $em->persist($category);
    $categories[$catName] = $category;
}

$em->flush();

$tags = [];
foreach ($tagsData as $tagName) {
    $tag = new Tag();
    $tag->setName($tagName);
    $em->persist($tag);
    $tags[$tagName] = $tag;
}

$em->flush();

$allTransactions = [];
foreach ($transactionsData as $data) {
    $transaction = new Transaction();
    $transaction->setDescription($data['description']);
    $transaction->setAmount($data['amount']);
    $transaction->setType($data['type']);
    $transaction->setCategory($categories[$data['category']]);
    $transaction->setUserId($data['user_id']);
    $transaction->setDate(new DateTime($data['date']));
    
    $tagKeys = array_keys($tags);
    shuffle($tagKeys);
    $numTags = rand(1, 3);
    for ($i = 0; $i < $numTags; $i++) {
        $transaction->addTag($tags[$tagKeys[$i]]);
    }
    
    $em->persist($transaction);
    $allTransactions[] = $transaction;
}

$em->flush();

echo "Database seeded successfully." . PHP_EOL;
echo "Seeded " . count($categoriesData) . " categories, " . count($tagsData) . " tags, and " . count($transactionsData) . " transactions" . PHP_EOL;
