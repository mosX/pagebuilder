<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Support\Audit\ActivityLog;
use App\Support\Tenancy\CustomDomains;
use App\Support\Tenancy\DnsLookup;
use App\Support\Tenancy\Subdomains;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The current company's own domains: adding, DNS verification, choosing the primary one.
 */
class DomainController extends Controller
{
    public function __construct(
        private CustomDomains $domains,
        private TenantContext $context,
        private ActivityLog $activity,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = $this->context->tenant();

        return response()->json([
            'domains' => Domain::query()->orderBy('host')->get()->map(fn (Domain $domain) => $this->present($domain)),
            // Where own domains point: a CNAME to the company subdomain.
            'target' => $tenant->slug.'.'.Subdomains::domain(),
            'site_url' => $tenant->siteUrl(),
            'max' => CustomDomains::MAX_PER_TENANT,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['host' => ['required', 'string', 'max:255']]);
        $host = $this->domains->normalize($request->string('host')->toString());

        if (! $host) {
            throw ValidationException::withMessages(['host' => 'Укажите домен, например acme.com или www.acme.com. Домены платформы добавлять нельзя.']);
        }

        if (Domain::withoutGlobalScope('tenant')->where('host', $host)->exists()) {
            throw ValidationException::withMessages(['host' => 'Этот домен уже подключён.']);
        }

        if (Domain::query()->count() >= CustomDomains::MAX_PER_TENANT) {
            throw ValidationException::withMessages(['host' => 'Можно подключить не больше '.CustomDomains::MAX_PER_TENANT.' доменов.']);
        }

        $domain = new Domain;
        $domain->forceFill([
            'tenant_id' => $this->context->tenant()->id,
            'host' => $host,
            'verification_token' => 'pagebuilder-verify='.Str::random(32),
        ])->save();

        $this->activity->record('domain.added', $host);

        return response()->json(['message' => "Домен {$host} добавлен. Настройте DNS и нажмите «Проверить».", 'domain' => $this->present($domain)], 201);
    }

    public function verify(Domain $domain, DnsLookup $dns): JsonResponse
    {
        $result = $this->domains->verify($domain, $dns);

        if ($result['verified']) {
            $this->activity->record('domain.verified', $domain->host);
        }

        return response()->json([
            'message' => match (true) {
                $result['verified'] && $result['points_here'] => 'Домен подтверждён и направлен на сайт.',
                $result['verified'] => 'Владение подтверждено. Осталось направить домен на сайт (CNAME) — сайт откроется, когда DNS обновится.',
                default => 'TXT-запись не найдена. Изменения DNS иногда расходятся до нескольких часов — попробуйте позже.',
            },
            'verified' => $result['verified'],
            'domain' => $this->present($domain->fresh() ?? $domain, $result['points_here']),
        ], $result['verified'] ? 200 : 422);
    }

    public function makePrimary(Domain $domain): JsonResponse
    {
        if (! $domain->isVerified()) {
            throw ValidationException::withMessages(['domain' => 'Сначала подтвердите домен.']);
        }

        DB::transaction(function () use ($domain) {
            Domain::query()->where('is_primary', true)->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
        });

        $this->domains->forget();
        $this->activity->record('domain.primary', $domain->host);

        return response()->json(['message' => "Сайт теперь открывается на {$domain->host}."]);
    }

    public function destroy(Domain $domain): JsonResponse
    {
        DB::transaction(function () use ($domain) {
            $domain->delete();

            // Another verified domain takes over as primary.
            if ($domain->is_primary) {
                Domain::query()->whereNotNull('verified_at')->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
            }
        });

        $this->domains->forget();
        $this->activity->record('domain.removed', $domain->host);

        return response()->json(['message' => "Домен {$domain->host} отключён."]);
    }

    /**
     * For the TLS proxy (Caddy on_demand_tls "ask"): 200 lets it issue a certificate for the host.
     * Only verified own domains qualify, so nobody can make the server request certificates
     * for arbitrary names. Answered for local requests only.
     */
    public function tlsCheck(Request $request): Response
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        $host = Str::lower($request->string('domain')->toString());

        return response('', $this->domains->find($host) ? 200 : 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Domain $domain, ?bool $pointsHere = null): array
    {
        return [
            'id' => $domain->id,
            'host' => $domain->host,
            'verified' => $domain->isVerified(),
            'is_primary' => $domain->is_primary,
            'txt_name' => $this->domains->txtName($domain),
            'txt_value' => $domain->verification_token,
            'points_here' => $pointsHere,
            'checked_at' => $domain->checked_at?->toIso8601String(),
        ];
    }
}
