<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Site\Forms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint of form blocks: a visitor's submission becomes a record.
 */
class FormSubmissionController extends Controller
{
    public function __invoke(Request $request, Forms $forms): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:8000'],
            'values' => ['present', 'array'],
            'captcha' => ['nullable', 'string', 'max:4096'],
            // Honeypot: hidden from people, filled in by naive bots.
            'website' => ['prohibited'],
        ]);

        // Multipart submissions (with files) carry the uploads among the values.
        $values = [...(array) $request->input('values'), ...(array) $request->file('values', [])];

        $forms->submit($request->string('token')->value(), $values, $request->input('captcha'), $request->ip());

        return response()->json(['message' => 'Заявка отправлена.'], 201);
    }
}
