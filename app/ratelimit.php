<?php
declare(strict_types=1);

/**
 * Small reusable rate limiter (Milestone 10.7).
 *
 *  - Sliding window: a client may make $max attempts in any $window seconds.
 *  - Storage is plain files in storage/ratelimit (one tiny file per bucket+key, flock-protected),
 *    so it does not depend on the database and keeps working if the database is struggling.
 *    storage/ is already denied to the web by .htaccess and the rewrite rules.
 *  - Keys are hashed, so no IP addresses or emails are written to disk in readable form.
 *  - Attempts that are refused are NOT recorded, so a blocked client is released exactly
 *    $window seconds after its oldest counted attempt instead of being punished forever.
 *  - If storage is unwritable the limiter fails OPEN (logged), so a disk problem cannot lock
 *    every customer and staff member out.
 *
 * Optional overrides in config/app.php (all have safe defaults here):
 *   const APP_RATE_LIMIT_ENABLED = true;
 *   const APP_TRUSTED_PROXIES = ['10.0.0.0/8', '203.0.113.5'];   // ONLY if the app sits behind your own proxy/CDN
 *   const APP_RATE_LIMITS = ['login_ip' => [10, 900], 'inquiry_ip' => [8, 600]];  // [max attempts, window seconds]
 */
final class TooManyRequestsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Too many attempts. Please wait ' . max(1, (int)ceil($retryAfter / 60)) . ' minute(s) and try again.');
    }
}

final class RateLimiter
{
    private const MAX_WINDOW = 86400;

    /** @var callable */
    private $clock;
    private string $dir;

    public function __construct(?string $dir = null, ?callable $clock = null)
    {
        $this->dir = rtrim($dir ?? (dirname(__DIR__) . '/storage/ratelimit'), '/\\');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Count one attempt, unless the limit is already reached.
     * @return array{allowed:bool,count:int,remaining:int,retry_after:int}
     */
    public function consume(string $bucket, string $key, int $max, int $window): array
    {
        return $this->run($bucket, $key, $max, $window, 'consume');
    }

    /** Look without counting. */
    public function peek(string $bucket, string $key, int $max, int $window): array
    {
        return $this->run($bucket, $key, $max, $window, 'peek');
    }

    /** Give back the most recent counted attempt (e.g. after a successful login). */
    public function forgive(string $bucket, string $key, int $window = self::MAX_WINDOW): void
    {
        $this->run($bucket, $key, PHP_INT_MAX, $window, 'forgive');
    }

    public function clear(string $bucket, string $key): void
    {
        @unlink($this->path($bucket, $key));
    }

    /** Delete counter files that can no longer matter. Called occasionally; safe to call any time. */
    public function purge(): int
    {
        $removed = 0;
        $cutoff = ($this->clock)() - self::MAX_WINDOW - 3600;
        foreach (glob($this->dir . '/*.rl') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function path(string $bucket, string $key): string
    {
        return $this->dir . '/' . hash('sha256', $bucket . '|' . $key) . '.rl';
    }

    private function run(string $bucket, string $key, int $max, int $window, string $mode): array
    {
        $window = max(1, min(self::MAX_WINDOW, $window));
        $open = ['allowed' => true, 'count' => 0, 'remaining' => $max, 'retry_after' => 0];

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            error_log('[ratelimit] cannot create ' . $this->dir . ' - rate limiting is not active');
            return $open;
        }
        $fh = @fopen($this->path($bucket, $key), 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            error_log('[ratelimit] cannot open counter file - rate limiting is not active');
            if (is_resource($fh)) { fclose($fh); }
            return $open;
        }
        try {
            $now = ($this->clock)();
            $stamps = [];
            foreach (preg_split('/\s+/', trim((string)stream_get_contents($fh))) ?: [] as $piece) {
                if ($piece !== '' && ctype_digit($piece) && (int)$piece > $now - $window && (int)$piece <= $now + 60) {
                    $stamps[] = (int)$piece;   // ignores corrupt, expired and far-future entries
                }
            }
            sort($stamps);

            if ($mode === 'forgive') {
                array_pop($stamps);
                $this->write($fh, $stamps);
                return $open;
            }

            $allowed = count($stamps) < $max;
            if ($allowed && $mode === 'consume') {
                $stamps[] = $now;
                $this->write($fh, $stamps);
            }
            $retry = $allowed ? 0 : max(1, $stamps[0] + $window - $now);
            return [
                'allowed' => $allowed,
                'count' => count($stamps),
                'remaining' => max(0, $max - count($stamps)),
                'retry_after' => $retry,
            ];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function write($fh, array $stamps): void
    {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode("\n", $stamps));
        fflush($fh);
    }
}

// ---------------------------------------------------------------- client address

/** True when $ip equals $range or lies inside it ("10.0.0.0/8", "2001:db8::/32", "203.0.113.5"). */
function ipInRange(string $ip, string $range): bool
{
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) { return false; }
    [$base, $bits] = array_pad(explode('/', $range, 2), 2, null);
    $baseBin = @inet_pton((string)$base);
    if ($baseBin === false || strlen($baseBin) !== strlen($ipBin)) { return false; }
    $bits = $bits === null ? strlen($baseBin) * 8 : (int)$bits;
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($baseBin, 0, $bytes)) { return false; }
    $rest = $bits % 8;
    if ($rest === 0) { return true; }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return ((ord($ipBin[$bytes]) ^ ord($baseBin[$bytes])) & $mask) === 0;
}

/**
 * The address to rate-limit on. Uses the TCP peer (REMOTE_ADDR), which a client cannot forge.
 * X-Forwarded-For is honoured ONLY when the peer is one of APP_TRUSTED_PROXIES, and then the
 * right-most address that is not itself a trusted proxy is used (the one your proxy appended).
 * IPv6 clients are grouped by /64, because one household/host controls a whole /64.
 */
function clientIp(?array $server = null, ?array $trustedProxies = null): string
{
    $server ??= $_SERVER;
    $trusted = $trustedProxies ?? (defined('APP_TRUSTED_PROXIES') ? (array)APP_TRUSTED_PROXIES : []);
    $peer = (string)($server['REMOTE_ADDR'] ?? '');
    if (filter_var($peer, FILTER_VALIDATE_IP) === false) { return 'unknown'; }

    $ip = $peer;
    $isTrusted = static function (string $addr) use ($trusted): bool {
        foreach ($trusted as $range) { if (ipInRange($addr, (string)$range)) { return true; } }
        return false;
    };
    if ($trusted && $isTrusted($peer) && !empty($server['HTTP_X_FORWARDED_FOR'])) {
        $chain = array_reverse(array_map('trim', explode(',', (string)$server['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) { break; }   // garbage: stop, keep the peer
            $ip = $candidate;
            if (!$isTrusted($candidate)) { break; }
        }
    }

    $bin = inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        if (str_starts_with($bin, "\0\0\0\0\0\0\0\0\0\0\xFF\xFF")) {            // IPv4-mapped (::ffff:a.b.c.d)
            return (string)inet_ntop(substr($bin, 12));
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return $ip;
}

// ---------------------------------------------------------------- application policy

/** [max attempts, window seconds] for a named rule. */
function rateLimitRule(string $name): array
{
    $defaults = [
        'login_ip'   => [10, 900],   // sign-in attempts per IP per 15 min (successful logins are given back)
        'login_user' => [20, 900],   // sign-in attempts per account per 15 min, across all IPs
        'inquiry_ip' => [8, 600],    // public inquiry submissions per IP per 10 min
        'find_ip'    => [6, 900],    // "find my appointments" requests per IP per 15 min
    ];
    $custom = defined('APP_RATE_LIMITS') ? (array)APP_RATE_LIMITS : [];
    $rule = $custom[$name] ?? $defaults[$name] ?? [10, 600];
    return [max(1, (int)$rule[0]), max(1, (int)$rule[1])];
}

function rateLimiter(): RateLimiter
{
    static $limiter;
    return $limiter ??= new RateLimiter();
}

function rateLimitEnabled(): bool
{
    return !defined('APP_RATE_LIMIT_ENABLED') || (bool)APP_RATE_LIMIT_ENABLED;
}

/**
 * Count one attempt for $rule/$key. When the limit is exceeded, sends 429 + Retry-After and throws
 * TooManyRequestsException (a RuntimeException, so existing "show the error message" handlers work).
 */
function enforceRateLimit(string $rule, ?string $key = null): void
{
    if (!rateLimitEnabled()) { return; }
    [$max, $window] = rateLimitRule($rule);
    if (random_int(1, 100) === 1) { rateLimiter()->purge(); }
    $result = rateLimiter()->consume($rule, $key ?? clientIp(), $max, $window);
    if (!$result['allowed']) {
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . $result['retry_after']);
        }
        throw new TooManyRequestsException($result['retry_after']);
    }
}

function forgiveRateLimit(string $rule, ?string $key = null): void
{
    if (!rateLimitEnabled()) { return; }
    rateLimiter()->forgive($rule, $key ?? clientIp(), rateLimitRule($rule)[1]);
}
