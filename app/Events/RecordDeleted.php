<?php

namespace App\Events;

class RecordDeleted extends RecordEvent
{
    public function name(): string
    {
        return 'record.deleted';
    }
}
