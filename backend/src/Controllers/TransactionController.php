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
