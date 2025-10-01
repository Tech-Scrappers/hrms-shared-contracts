<?php

namespace Shared\Base;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shared\Contracts\RepositoryInterface;
use Shared\Contracts\TenantAwareInterface;
use Shared\Traits\TenantAwareTrait;

abstract class BaseRepository implements RepositoryInterface, TenantAwareInterface
{
    use TenantAwareTrait;

    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(string $id): ?Model
    {
        $this->ensureTenantId();

        return $this->model->where('tenant_id', $this->tenantId)->find($id);
    }

    public function create(array $data): Model
    {
        $this->ensureTenantId();

        $data['tenant_id'] = $this->tenantId;

        return $this->model->create($data);
    }

    public function update(string $id, array $data): Model
    {
        $this->ensureTenantId();

        $model = $this->find($id);
        if (! $model) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        }

        $model->update($data);

        return $model->fresh();
    }

    public function delete(string $id): bool
    {
        $this->ensureTenantId();

        $model = $this->find($id);
        if (! $model) {
            return false;
        }

        return $model->delete();
    }

    public function getAll(array $filters = []): array
    {
        $this->ensureTenantId();

        $query = $this->model->where('tenant_id', $this->tenantId);

        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        return $query->get()->toArray();
    }

    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $this->ensureTenantId();

        $query = $this->model->where('tenant_id', $this->tenantId);

        $results = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $results->items(),
            'pagination' => [
                'current_page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'last_page' => $results->lastPage(),
                'from' => $results->firstItem(),
                'to' => $results->lastItem(),
            ],
        ];
    }

    protected function getQuery(): Builder
    {
        $this->ensureTenantId();

        return $this->model->where('tenant_id', $this->tenantId);
    }
}
