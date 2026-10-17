<?php

namespace App\Events;

/**
 * A visitor sent a form on the company site (the record it created is attached).
 */
class FormSubmitted extends RecordEvent
{
    public function name(): string
    {
        return 'form.submitted';
    }
}
