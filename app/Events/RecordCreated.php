<?php

namespace App\Events;

class RecordCreated extends RecordEvent
{
    public function name(): string
    {
        return 'record.created';
    }
}
