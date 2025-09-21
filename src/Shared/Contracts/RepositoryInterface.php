<?php

namespace Shared\Contracts;

interface RepositoryInterface
{
    public function find(string $id): ?object;
    public function create(array $data): object;
    public function update(string $id, array $data): object;
    public function delete(string $id): bool;
    public function getAll(array $filters = []): array;
    public function paginate(int $perPage = 15, int $page = 1): array;
}
