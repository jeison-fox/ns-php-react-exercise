<?php

namespace App\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TransactionController
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $skip = isset($params['skip']) ? (int)$params['skip'] : 0;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 100;

        $transactionRepo = $this->em->getRepository(Transaction::class);

        $transactions = $transactionRepo->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')
            ->addSelect('c')
            ->leftJoin('t.tags', 'tags')
            ->addSelect('tags')
            ->setFirstResult($skip)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $data = array_map(fn($t) => $t->toArray(), $transactions);

        $response->getBody()->write(json_encode($data, JSON_PRESERVE_ZERO_FRACTION));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function grid(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $size = isset($params['size']) ? max(1, min(100, (int)$params['size'])) : 10;
        $offset = ($page - 1) * $size;

        $allowedSortColumns = ['id', 'description', 'amount', 'type', 'date', 'category_name'];

        $sortBy = isset($params['sort_by']) && in_array($params['sort_by'], $allowedSortColumns)
            ? $params['sort_by']
            : 'date';

        $sortOrder = isset($params['sort_order']) && strtolower($params['sort_order']) === 'asc'
            ? 'ASC'
            : 'DESC';

        $sortColumnMap = [
            'id' => 't.id',
            'description' => 't.description',
            'amount' => "CASE WHEN t.type = 'credit' THEN t.amount ELSE -t.amount END",
            'type' => 't.type',
            'date' => 't.date',
            'category_name' => 'c.name',
        ];

        $orderByColumn = $sortColumnMap[$sortBy];
        
        $conn = $this->em->getConnection();

        $countSql = "SELECT COUNT(*) as total FROM transactions";
        $totalResult = $conn->fetchAssociative($countSql);
        $total = (int)$totalResult['total'];
        
        // Raw SQL query with JOIN for transactions and categories
        $sql = "
            SELECT
                t.id,
                t.description,
                t.amount,
                t.type,
                t.date,
                t.user_id,
                t.category_id,
                c.id as category_rel_id,
                c.name as category_name
            FROM transactions t
            LEFT JOIN categories c ON t.category_id = c.id
            ORDER BY {$orderByColumn} {$sortOrder}
            LIMIT :limit OFFSET :offset
        ";

        $transactions = $conn->fetchAllAssociative($sql, [
            'limit' => $size,
            'offset' => $offset,
        ]);
        
        // Get tags for each transaction using a separate query
        $transactionIds = array_column($transactions, 'id');
        $tags = [];
        
        if (!empty($transactionIds)) {
            $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));

            $tagsSql = "
                SELECT tt.transaction_id, tg.id, tg.name
                FROM transaction_tags tt
                JOIN tags tg ON tt.tag_id = tg.id
                WHERE tt.transaction_id IN ({$placeholders})
                ORDER BY tg.name
            ";

            $tagsResult = $conn->fetchAllAssociative($tagsSql, $transactionIds);

            foreach ($tagsResult as $tag) {
                $txId = $tag['transaction_id'];

                if (!isset($tags[$txId])) {
                    $tags[$txId] = [];
                }

                $tags[$txId][] = [
                    'id' => (int)$tag['id'],
                    'name' => $tag['name'],
                ];
            }
        }
        
        // Build response items
        $items = array_map(function ($row) use ($tags) {
            $txId = (int)$row['id'];

            return [
                'id' => $txId,
                'description' => $row['description'],
                'amount' => (float)$row['amount'],
                'type' => $row['type'],
                'date' => $row['date'],
                'user_id' => (int)$row['user_id'],
                'category_id' => (int)$row['category_id'],
                'category_rel' => [
                    'id' => (int)$row['category_rel_id'],
                    'name' => $row['category_name'],
                ],
                'tags' => $tags[$txId] ?? [],
            ];
        }, $transactions);
        
        $result = [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'total_pages' => (int)ceil($total / $size),
        ];
        
        $response->getBody()->write(json_encode($result, JSON_PRESERVE_ZERO_FRACTION));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $transactionRepo = $this->em->getRepository(Transaction::class);

        $transaction = $transactionRepo->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')
            ->addSelect('c')
            ->leftJoin('t.tags', 'tags')
            ->addSelect('tags')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$transaction) {
            $error = ['detail' => 'Transaction not found'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode($transaction->toArray(), JSON_PRESERVE_ZERO_FRACTION));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);

        if (
            empty($data['description']) ||
            !isset($data['amount']) ||
            empty($data['type']) ||
            !isset($data['category_id']) ||
            !isset($data['user_id'])
        ) {
            $error = ['detail' => 'Missing required fields'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        }

        $categoryRepo = $this->em->getRepository(Category::class);
        $category = $categoryRepo->find($data['category_id']);

        if (!$category) {
            $error = ['detail' => 'Category not found'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        }

        $transaction = new Transaction();
        $transaction->setDescription($data['description']);
        $transaction->setAmount((float)$data['amount']);
        $transaction->setType($data['type']);
        $transaction->setCategory($category);
        $transaction->setUserId((int)$data['user_id']);

        if (isset($data['date'])) {
            $transaction->setDate(new \DateTime($data['date']));
        }

        $this->em->persist($transaction);
        $this->em->flush();

        $response->getBody()->write(json_encode($transaction->toArray(), JSON_PRESERVE_ZERO_FRACTION));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);

        $transactionRepo = $this->em->getRepository(Transaction::class);
        $transaction = $transactionRepo->find($id);

        if (!$transaction) {
            $error = ['detail' => 'Transaction not found'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if (isset($data['description'])) {
            $transaction->setDescription($data['description']);
        }
        if (isset($data['amount'])) {
            $transaction->setAmount((float)$data['amount']);
        }
        if (isset($data['type'])) {
            $transaction->setType($data['type']);
        }
        if (isset($data['category_id'])) {
            $categoryRepo = $this->em->getRepository(Category::class);
            $category = $categoryRepo->find($data['category_id']);
            if ($category) {
                $transaction->setCategory($category);
            }
        }
        if (isset($data['user_id'])) {
            $transaction->setUserId((int)$data['user_id']);
        }

        $this->em->flush();

        $this->em->refresh($transaction);

        $response->getBody()->write(json_encode($transaction->toArray(), JSON_PRESERVE_ZERO_FRACTION));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $transactionRepo = $this->em->getRepository(Transaction::class);

        $transaction = $transactionRepo->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')
            ->addSelect('c')
            ->leftJoin('t.tags', 'tags')
            ->addSelect('tags')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$transaction) {
            $error = ['detail' => 'Transaction not found'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $data = $transaction->toArray();

        $this->em->remove($transaction);
        $this->em->flush();

        $response->getBody()->write(json_encode($data, JSON_PRESERVE_ZERO_FRACTION));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
