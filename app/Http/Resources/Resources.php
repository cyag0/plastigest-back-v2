<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class Resources extends JsonResource
{
    /**
     * Context data for resource transformation
     */
    protected array $context;

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @param  array  $context  Additional context data for transformation
     */
    public function __construct($resource, $context = [])
    {
        if (!is_array($context)) {
            $context = [];
        }

        parent::__construct($resource);
        $this->context = $context;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Validate that resource is a Model instance
        if (!($this->resource instanceof Model)) {
            throw new \InvalidArgumentException('Resource must be an instance of Eloquent Model');
        }

        return $this->formatter($this->resource, $request->all(), $this->context);
    }

    /**
     * Get context value by key with optional default
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    protected function getContext(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * Check if context has a specific key
     *
     * @param  string  $key
     * @return bool
     */
    protected function hasContext(string $key): bool
    {
        return array_key_exists($key, $this->context);
    }

    /**
     * Format the resource data based on context
     *
     * @param  Model  $resource  The Eloquent model instance
     * @param  array  $data      Request data
     * @param  array  $context   Context data for transformation
     * @return array<string, mixed>
     */
    abstract protected function formatter(Model $resource, array $data, array $context): array;
}
