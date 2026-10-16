<?php
declare(strict_types=1);
namespace Fpanel\Http;

use Fpanel\Database\Connection;

/**
 * Database-backed sessions (not files) so all devices can be revoked,
 * reviewed from the UI, and no shared /tmp is needed between php-fpm workers.
 */
final class Session
{
    public const COOKIE = 'fp_session';
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(
        private Request $request,
        private string $lifetime = 'P14D'
    ) {}

    private function expiry(): string
    {
        return gmdate('Y-m-d H:i:s', time() + (int)ini_get('session.gc_maxlifetime') ?: 1209600);
    }

    /**
     * Start a session.
     *
     * $kind = 'auth' is a regular logged-in session. Other kinds are used for
     * intermediate steps, e.g. 'totp': the row EXISTS but does not grant
     * access yet, so user() ignores it until its kind is promoted to 'auth'.
     */
    public function start(?int $userId = null, string $kind = 'auth', int $seconds = 1209600): string
    {
        $token = bin2hex(random_bytes(32));
        Connection::insert('sessions', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'ip'         => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'kind'       => $kind,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $seconds),
        ]);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $token, [
                'expires'  => time() + $seconds,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
        $this->loaded = false;
        $this->user = null;
        return $token;
    }

    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return $this->user = null;
        }
        $row = Connection::one(
            "SELECT s.id AS sid, s.expires_at, u.* FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :h AND s.kind = 'auth'
               AND s.expires_at > datetime('now')",
            ['h' => hash('sha256', $token)]
        );
        if ($row === null) {
            return $this->user = null;
        }
        Connection::run(
            "UPDATE sessions SET last_seen_at = datetime('now') WHERE id = :id",
            ['id' => $row['sid']]
        );
        unset($row['sid']);
        return $this->user = $row;
    }

    /**
     * User from an intermediate session (e.g. 'totp'), or null if none exists.
     *
     * The IP is compared as well so a stolen cookie cannot be used to
     * complete the login from another network.
     */
    public function pendingUser(string $kind): ?array
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return null;
        }
        $row = Connection::one(
            "SELECT s.id AS sid, u.* FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :h AND s.kind = :k
               AND s.expires_at > datetime('now') AND s.ip = :ip",
            ['h' => hash('sha256', $token), 'k' => $kind, 'ip' => $this->request->ip()]
        );
        if ($row === null) {
            return null;
        }
        unset($row['sid']);
        return $row;
    }

    /**
     * Promote an intermediate session into a full logged-in session.
     *
     * The cookie is not replaced, so there is no gap in which the user could
     * end up logged out after 2FA succeeds.
     */
    public function promotePending(string $kind, int $seconds = 1209600): bool
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return false;
        }
        $st = Connection::run(
            "UPDATE sessions
              SET kind = 'auth', expires_at = :e, last_seen_at = datetime('now')
              WHERE token_hash = :h AND kind = :k AND ip = :ip",
            [
                'e'  => gmdate('Y-m-d H:i:s', time() + $seconds),
                'h'  => hash('sha256', $token),
                'k'  => $kind,
                'ip' => $this->request->ip(),
            ]
        );
        if ($st > 0 && !headers_sent()) {
            // The pending cookie is created with a 5-minute lifetime. Without
            // extending it here, the user drops out 5 minutes after 2FA works.
            setcookie(self::COOKIE, (string)$token, [
                'expires'  => time() + $seconds,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
        // Force a re-read so user() sees the new kind.
        $this->loaded = false;
        $this->user   = null;
        return $st > 0;
    }

    /** Drop an intermediate session without affecting other logins. */
    public function dropPending(string $kind): void
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return;
        }
        Connection::run(
            'DELETE FROM sessions WHERE token_hash = :h AND kind = :k',
            ['h' => hash('sha256', $token), 'k' => $kind]
        );
    }


    public function destroy(): void
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token !== null && $token !== '') {
            Connection::run('DELETE FROM sessions WHERE token_hash = :h',
                ['h' => hash('sha256', $token)]);
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        }
        $this->loaded = true;
        $this->user = null;
    }

    public function destroyAllForUser(int $userId): void
    {
        Connection::run('DELETE FROM sessions WHERE user_id = :u', ['u' => $userId]);
    }

    public function cleanup(): void
    {
        Connection::run("DELETE FROM sessions WHERE expires_at <= datetime('now')");
    }
}
