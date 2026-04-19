<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Common base for action-style service classes. A concrete service exposes
 * an execute() method and optionally a rules() method declaring validation.
 */
abstract class BaseService
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
    }
}
