<?php
declare(strict_types=1);

namespace App\Adapter\Api;

use App\Core\Exception\InvalidSchema;
use App\Core\Exception\SchemaAlreadyExists;
use App\Core\Service\SchemaManager;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SchemaController
{
    public function __construct(private SchemaManager $schemas)
    {
    }

    #[Route('/api/schemas', name: 'schema_create', methods: ['POST'], format: 'json')]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->schemas->create($request->toArray())->toArray(), 201);
        } catch (JsonException $error) {
            return new JsonResponse(['error' => $error->getMessage()], 400);
        } catch (InvalidSchema $error) {
            return new JsonResponse(['error' => $error->getMessage()], 422);
        } catch (SchemaAlreadyExists $error) {
            return new JsonResponse(['error' => $error->getMessage()], 409);
        }
    }
}
