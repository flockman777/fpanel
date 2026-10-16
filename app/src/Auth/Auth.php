<?php
declare(strict_types=1);
namespace Fpanel\Auth;

use Fpanel\Database\Connection;
use Fpanel\Http\Session;
use Fpanel\Service\Totp;
use Fpanel\Support\Audit;

final class Auth
{
    public const MAX_FAILS   = 5;      // attempts per IP
    public const WINDOW_MIN = 15;     // within this window
    public const LOCK_MIN   = 15;     // lock duration

    /**
     * Per-account limit, far looser than the per-IP limit.
     *
     * A per-IP limit alone can be bypassed by using several different IPs at
     * once, so a single account name also gets its own limit. The value is much
     * higher so one person mistyping repeatedly does not easily lock it.
     */
    public const MAX_FAILS_USER = 25;

    public function __construct(private Session $session) {}

    private function recentFails(string $ip): int
    {
        return (int)Connection::value(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = :ip AND success = 0
               AND created_at > datetime('now', :win)",
            ['ip' => $ip, 'win' => '-' . self::WINDOW_MIN . ' minutes']
        );
    }

    /** Recent failures for one account name, from any IP. */
    private function recentFailsForUser(?string $username): int
    {
        if ($username === null || trim($username) === '') {
            return 0;
        }
        return (int)Connection::value(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :u AND success = 0
               AND created_at > datetime('now', :win)",
            ['u' => $username, 'win' => '-' . self::WINDOW_MIN . ' minutes']
        );
    }

    public function isLocked(string $ip): bool
    {
        return $this->recentFails($ip) >= self::MAX_FAILS;
    }

    /**
     * Account-name-based lock.
     *
     * This closes off attacks from many IPs at once: one IP may only try
     * MAX_FAILS times, but from 25 different IPs one account stays locked.
     * Names are normalized first because the form accepts an email identity.
     */
    public function isUserLocked(?string $username): bool
    {
        return $this->recentFailsForUser(self::normalizeUser($username))
            >= self::MAX_FAILS_USER;
    }

    /** Normalize email and account name so they do not count as two targets. */
    private static function normalizeUser(?string $username): ?string
    {
        $u = trim((string)$username);
        if ($u === '') {
            return null;
        }
        // Distinguish only by the part before "@": two emails in the same domain
        // are still counted separately, but the same address is not.
        return str_contains($u, '@') ? substr($u, 0, (int)strpos($u, '@')) : $u;
    }

    public function lockRemaining(string $ip): int
    {
        return (int)Connection::value(
            "SELECT CAST(MAX(CAST((julianday(datetime('now')) - julianday(created_at)) * 1440 AS INTEGER)) AS INTEGER)
             FROM login_attempts
             WHERE ip = :ip AND success = 0
               AND created_at > datetime('now', :win)",
            ['ip' => $ip, 'win' => '-' . self::WINDOW_MIN . ' minutes']
        );
    }

    private function record(string $ip, ?string $username, bool $ok): void
    {
        Connection::insert('login_attempts', [
            'ip' => $ip, 'username' => self::normalizeUser($username), 'success' => $ok ? 1 : 0,
        ]);
        Connection::run(
            "DELETE FROM login_attempts WHERE created_at < datetime('now','-1 day')"
        );
    }

    /** @return array{ok:bool,error?:string,need_totp?:bool,user_id?:int} */
    public function attempt(string $username, string $password, string $ip): array
    {
        if ($this->isLocked($ip) || $this->isUserLocked($username)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in '
                . self::LOCK_MIN . ' minutes.'];
        }
        $user = Connection::one(
            'SELECT * FROM users WHERE username = :u OR email = :u',
            ['u' => $username]
        );
        // Always run verify so response time does not leak whether the user exists
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $pass = PasswordHasher::verify($password, $hash);

        if ($user === null || !$pass) {
            $this->record($ip, $username, false);
            Audit::log('auth.login', $user['id'] ?? null, $username, 'user',
                $user['id'] ?? null, $ip, 'denied');
            return ['ok' => false, 'error' => 'Wrong username or password.'];
        }
        if ($user['status'] !== 'active') {
            $this->record($ip, $username, false);
            Audit::log('auth.login', (int)$user['id'], $user['username'], 'user',
                $user['id'], $ip, 'denied', ['reason' => 'suspended']);
            return ['ok' => false, 'error' => 'Account suspended. Contact an administrator.'];
        }
        if (PasswordHasher::needsRehash($user['password_hash'])) {
            Connection::update('users',
                ['password_hash' => PasswordHasher::hash($password), 'updated_at' => gmdate('Y-m-d H:i:s')],
                'id = :id', ['id' => $user['id']]);
        }

        $needsTotp = (int)($user['totp_enabled'] ?? 0) === 1
            && trim((string)($user['totp_secret'] ?? '')) !== '';

        // The success record is written only after 2FA actually passes,
        // so a correct password with a wrong code is not logged as "success".
        if (!$needsTotp) {
            $this->record($ip, $username, true);
            $this->session->start((int)$user['id']);
            $this->finalize((int)$user['id'], (string)$user['username'], $ip, 'password');
        }

        if ($needsTotp) {
            // Intermediate session: kind 'totp' means not logged in yet. Short
            // duration so it cannot be left hanging.
            $this->session->start((int)$user['id'], 'totp', 300);
            return ['ok' => true, 'need_totp' => true, 'error' => null,
                    'user_id' => (int)$user['id']];
        }

        // user_id is returned so the caller can use it right away.
        // $this->session->user() cannot be used here: it reads the cookie from
        // the Request built before the response, while the new session cookie
        // is created within this same request.
        return ['ok' => true, 'need_totp' => false, 'error' => null,
                'user_id' => (int)$user['id']];
    }

    /**
     * Second login step: verify the TOTP code or a recovery code.
     *
     * Called only when attempt() has already returned need_totp, so user_id
     * comes from the intermediate session, not from the request input.
     */
    public function confirmTotp(string $code, string $ip): array
    {
        $pending = $this->session->pendingUser('totp');
        if ($pending === null) {
            return ['ok' => false, 'error' => 'The verification session expired. Please log in again.'];
        }
        if ($this->isLocked($ip) || $this->isUserLocked((string)$pending['username'])) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in '
                . self::LOCK_MIN . ' minutes.'];
        }

        $username = (string)$pending['username'];
        $r = Totp::verifyLogin($username, $code);
        if (empty($r['ok'])) {
            $this->record($ip, $username, false);
            Audit::log('auth.totp', (int)$pending['id'], $username, 'user',
                $pending['id'], $ip, 'denied');
            return ['ok' => false, 'error' => (string)$r['error']];
        }

        $this->record($ip, $username, true);
        $this->session->promotePending('totp');
        $this->finalize((int)$pending['id'], $username, $ip, (string)$r['method']);
        return ['ok' => true, 'error' => null];
    }

    /**
     * Shared login tail for both the password step and the 2FA step.
     *
     * Does not touch the session: on the password step the new session is
     * created in attempt(), on the 2FA step the intermediate session has
     * already been promoted by promotePending(). Recreating it here would
     * leave the promoted row dangling as a valid, never-cleaned session.
     */
    private function finalize(int $userId, string $username, string $ip, string $method): void
    {
        Connection::update('users', [
            'last_login_at' => gmdate('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'failed_logins' => 0,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);
        Audit::log('auth.login', $userId, $username, 'user', $userId, $ip, 'ok',
            ['method' => $method]);
    }

    public function user(): ?array  { return $this->session->user(); }
    public function check(): bool  { return $this->session->user() !== null; }
    public function isAdmin(): bool
    {
        $u = $this->session->user();
        return $u !== null && $u['role'] === 'admin';
    }
    public function id(): ?int
    {
        $u = $this->session->user();
        return $u === null ? null : (int)$u['id'];
    }
    public function logout(): void
    {
        $u = $this->session->user();
        if ($u !== null) {
            Audit::log('auth.logout', (int)$u['id'], $u['username'], 'user', $u['id'], '');
        }
        $this->session->destroy();
    }
}
