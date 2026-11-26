<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Device / browser / OS from a User-Agent string.
 *
 * HAND-ROLLED RATHER THAN A PACKAGE, deliberately. The backend CLAUDE.md says
 * not to change dependencies without approval, and the two candidates
 * (jenssegers/agent, whichcms/device-detector) both pull in large regex
 * databases to answer a question this admin asks at a very coarse grain: is it
 * a phone, which browser family, which OS family. Three buckets per axis does
 * not justify a dependency, and a wrong guess here costs a mislabelled column,
 * not a wrong decision.
 *
 * ORDER IS THE WHOLE ALGORITHM. Every browser lies in its User-Agent for
 * compatibility — Edge contains "Chrome" and "Safari", Chrome contains
 * "Safari" — so the tests run from most specific to least, and the first match
 * wins. Reordering these lists silently relabels traffic.
 */
final class UserAgentParser
{
    /**
     * @return array{device_type: string|null, browser: string|null, os: string|null, is_bot: bool}
     */
    public static function parse(?string $ua): array
    {
        if ($ua === null || trim($ua) === '') {
            return ['device_type' => null, 'browser' => null, 'os' => null, 'is_bot' => false];
        }

        return [
            'device_type' => self::deviceType($ua),
            'browser'     => self::browser($ua),
            'os'          => self::os($ua),
            'is_bot'      => self::isBot($ua),
        ];
    }

    /**
     * Crawlers, previewers and scripted clients.
     *
     * Used to SKIP recording a visit: a table of bonus-page visitors that is
     * mostly Googlebot and link-preview fetchers tells an operator nothing,
     * and each one would otherwise cost a paid geo lookup.
     *
     * `bot` as a bare substring would catch Cubot and Elabot phones, so the
     * generic tokens are matched with a word boundary.
     */
    public static function isBot(string $ua): bool
    {
        $needles = [
            'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'baiduspider', 'applebot',
            'slurp', 'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'petalbot', 'bytespider',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'slackbot', 'telegrambot',
            'whatsapp', 'discordbot', 'embedly', 'pinterest', 'redditbot', 'skypeuripreview',
            'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client', 'java/',
            'okhttp', 'axios/', 'node-fetch', 'got/', 'postmanruntime', 'insomnia',
            'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
            'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptimerobot', 'statuscake',
        ];

        $haystack = strtolower($ua);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        // Generic tokens, bounded so they cannot match a phone model name.
        return (bool) preg_match('/\b(bot|crawler|spider|scraper|monitoring)\b/i', $ua);
    }

    private static function deviceType(string $ua): ?string
    {
        // Tablets BEFORE phones: an Android tablet's UA says "Android" and
        // omits "Mobile", and an iPad says "iPad" while also saying "Mobile".
        if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobile|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|Windows Phone/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/SmartTV|AppleTV|GoogleTV|HbbTV|NetCast|Roku/i', $ua)) {
            return 'tv';
        }

        return 'desktop';
    }

    private static function browser(string $ua): ?string
    {
        // Most specific first — see the class docblock. Edge claims Chrome and
        // Safari; Chrome claims Safari; Opera claims Chrome.
        $map = [
            'Edge'              => '/Edg[A-Z]?\//',
            'Opera'             => '/OPR\/|Opera/',
            'Samsung Internet'  => '/SamsungBrowser/',
            'Yandex Browser'    => '/YaBrowser/',
            'Vivaldi'           => '/Vivaldi/',
            'Brave'             => '/Brave/',
            'UC Browser'        => '/UCBrowser/',
            'Firefox'           => '/Firefox\/|FxiOS/',
            'Chrome'            => '/Chrome\/|CriOS/',
            'Safari'            => '/Safari\//',
            'Internet Explorer' => '/MSIE |Trident\//',
        ];

        foreach ($map as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return null;
    }

    private static function os(string $ua): ?string
    {
        // iOS before macOS: an iPhone UA contains "like Mac OS X".
        $map = [
            'iOS'       => '/iPhone|iPad|iPod|iOS |CPU OS /',
            'Android'   => '/Android/',
            'Windows'   => '/Windows NT|Windows Phone/',
            'macOS'     => '/Macintosh|Mac OS X/',
            'Chrome OS' => '/CrOS/',
            'Ubuntu'    => '/Ubuntu/',
            'Linux'     => '/Linux|X11/',
        ];

        foreach ($map as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return null;
    }
}
