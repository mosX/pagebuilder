<?php

namespace App\Events;

class RecordUpdated extends RecordEvent
{
    public function name(): string
    {
        return 'record.updated';
    }
}
