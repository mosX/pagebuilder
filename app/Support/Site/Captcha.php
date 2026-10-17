<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Captcha of site forms: Yandex SmartCaptcha or Cloudflare Turnstile. The widget runs
 * in the visitor's browser; its token is checked with the provider before a submission
 * is accepted. Both providers are free and do not need a puzzle for most people.
 */
class Captcha
{
    private const PROVIDERS = [
        'yandex' => [
            'script' => 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload',
            'origin' => 'https://smartcaptcha.yandexcloud.net',
            'verify' => 'https://smartcaptcha.yandexcloud.net/validate',
        ],
        'turnstile' => [
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            'origin' => 'https://challenges.cloudflare.com',
            'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ],
    ];

    public function enabled(): bool
    {
        return isset(self::PROVIDERS[$this->provider()]) && $this->siteKey() !== '' && (string) config('captcha.secret') !== '';
    }

    public function provider(): string
    {
        return (string) config('captcha.provider');
    }

    public function siteKey(): string
    {
        return (string) config('captcha.site_key');
    }

    /**
     * What the form widget needs: provider, public key and script.
     *
     * @return array{provider: string, site_key: string, script: string}|null
     */
    public function widget(): ?array
    {
        return $this->enabled()
            ? ['provider' => $this->provider(), 'site_key' => $this->siteKey(), 'script' => self::PROVIDERS[$this->provider()]['script']]
            : null;
    }

    /**
     * Hosts the Content Security Policy must allow for the widget (script, frame, requests).
     *
     * @return list<string>
     */
    public function cspOrigins(): array
    {
        return $this->enabled() ? [self::PROVIDERS[$this->provider()]['origin']] : [];
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return false;
        }

        $secret = (string) config('captcha.secret');

        try {
            $response = $this->provider() === 'yandex'
                ? Http::timeout(5)->get(self::PROVIDERS['yandex']['verify'], ['secret' => $secret, 'token' => $token, 'ip' => $ip])
                : Http::timeout(5)->asForm()->post(self::PROVIDERS['turnstile']['verify'], ['secret' => $secret, 'response' => $token, 'remoteip' => $ip]);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return $this->provider() === 'yandex'
            ? $response->json('status') === 'ok'
            : $response->json('success') === true;
    }
}
