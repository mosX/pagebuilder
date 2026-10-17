<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Audit\ActivityLog;
use App\Support\Blocks\BlockRegistry;
use App\Support\Collections\PagesCollection;
use App\Support\Site\SiteAccounts;
use App\Support\Site\SiteDesign;
use App\Support\Site\SiteLanguages;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings of the current company's site: theme, menu, logo, favicon, languages
 * and the visitor account area.
 * Header and footer trees are edited in the constructor (targets layout:header / layout:footer).
 */
class SiteSettingsController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private SiteDesign $design,
        private SiteLanguages $languages,
        private SiteAccounts $accounts,
    ) {}

    public function show(PagesCollection $pages): JsonResponse
    {
        $tenant = $this->context->tenant();
        $settings = $this->design->for($tenant);
        $collection = $pages->find($tenant);

        return response()->json([
            'theme' => $this->design->theme($settings->theme),
            'menu' => $this->design->menu($settings->menu),
            'logo' => $settings->logo,
            'favicon' => $settings->favicon,
            'languages' => $this->languages->of($settings),
            'account' => $this->accounts->of($settings),
            'design' => $this->design->present($settings),
            'site_url' => $tenant->siteUrl(),
            'locales' => collect(SiteLanguages::LOCALES)->map(fn (string $label, string $code) => ['value' => $code, 'label' => $label])->values(),
            // Collections with an e-mail field can show visitors their own records.
            'account_collections' => Collection::query()->with('fields')->orderBy('label')->get()
                ->map(fn (Collection $c) => [
                    'name' => $c->name,
                    'label' => $c->label,
                    'email_fields' => $c->fields
                        ->filter(fn (CollectionField $f) => in_array($f->type, [FieldType::Email, FieldType::String], true))
                        ->map(fn (CollectionField $f) => ['name' => $f->name, 'label' => $f->label, 'type' => $f->type->value])
                        ->values(),
                    // List fields can be the status of a submission.
                    'status_fields' => $c->fields
                        ->filter(fn (CollectionField $f) => $f->type === FieldType::Select)
                        ->map(fn (CollectionField $f) => ['name' => $f->name, 'label' => $f->label])
                        ->values(),
                ])
                ->filter(fn (array $c) => $c['email_fields']->isNotEmpty())
                ->values(),
            'fonts' => collect(SiteDesign::FONTS)->map(fn (array $font, string $key) => ['value' => $key, 'label' => $font[0]])->values(),
            'pages' => $collection
                ? DB::table($collection->table_name)->orderBy('id')->get(['title', 'slug', 'is_published'])
                : [],
        ]);
    }

    public function update(Request $request, BlockRegistry $blocks, ActivityLog $activity): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['present', 'array'],
            'menu' => ['present', 'array'],
            'logo' => ['nullable', 'string', 'max:1024'],
            'favicon' => ['nullable', 'string', 'max:1024'],
            'languages' => ['sometimes', 'array'],
            'account' => ['sometimes', 'array'],
        ]);

        $tenant = $this->context->tenant();
        $settings = $this->design->for($tenant);
        $settings->forceFill([
            'languages' => array_key_exists('languages', $data) ? $this->languages->normalize($data['languages']) : $settings->languages,
            'account' => array_key_exists('account', $data) ? $this->accounts->normalize($tenant, $data['account']) : $settings->account,
            'theme' => $this->design->theme($data['theme']),
            'menu' => $this->design->menu($data['menu']),
            'logo' => $blocks->safeUrl((string) ($data['logo'] ?? '')) ?: null,
            'favicon' => $blocks->safeUrl((string) ($data['favicon'] ?? '')) ?: null,
        ])->save();

        $activity->record('site.updated', 'Оформление сайта');

        return response()->json([
            'message' => 'Настройки сайта сохранены.',
            'theme' => $settings->theme,
            'menu' => $settings->menu,
            'languages' => $this->languages->of($settings),
            'account' => $this->accounts->of($settings),
            'design' => $this->design->present($settings),
        ]);
    }
}
