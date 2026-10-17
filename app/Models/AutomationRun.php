<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $automation_id
 * @property bool $success
 * @property string|null $subject
 * @property string|null $message
 * @property Carbon|null $created_at
 */
class AutomationRun extends Model
{
    public const UPDATED_AT = null;

    public const KEEP = 50;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
        ];
    }
}
