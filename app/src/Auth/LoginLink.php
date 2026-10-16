<?php
declare(strict_types=1);
namespace Fpanel\Auth;

use Fpanel\Database\Connection;

/**
 * Single-use login link.
 *
 * Used to sign in from a new device or browser without typing a password:
 * the panel sends the link, the user opens it, and is signed in right away.
 *
 * Two rules keep this safe even if the link leaks:
 *   - only the token hash is stored, so a database leak does not directly
 *     mean access
 *   - the token dies as soon as it is used (DELETE in the same transaction), so
 *     a link that gets copied around cannot be reused
 *
 * Short lifetime (15 minutes) so links left behind in an inbox or download
 * history quickly lose their meaning.
 */
final class LoginLink
{
    /** Minutes before the link expires. */
    public const TTL_MIN = 15;

    private const PREFIX = 'fpl_';

    private function __construct() {}

    /**
     * Issue a new link for one user.
     *
     * @return array{ok:bool,error?:string,token?:string,expires_at?:string}
     */
    public static function issue(string $username, string $ip = ''): array
    {
        $uid = (int)Connection::value(
            'SELECT id FROM users WHERE username = :u', ['u' => $username]
        );
        if ($uid < 1) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        // Revoke old tokens first: only one active link per account, so a
        // leaked link cannot be used alongside a new one.
        Connection::run('DELETE FROM login_links WHERE user_id = :u', ['u' => $uid]);

        $token = self::PREFIX . bin2hex(random_bytes(24));
        Connection::insert('login_links', [
            'user_id'    => $uid,
            'token_hash' => hash('sha256', $token),
            'ip_hint'    => substr($ip, 0, 45),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::TTL_MIN * 60)),
        ]);
        return ['ok' => true, 'error' => null, 'token' => $token,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::TTL_MIN * 60))];
    }

    /**
     * Exchange the token for a user id, burning the token in the process.
     *
     * The deletion and the read happen in one transaction: if two requests
     * arrive at the same time, only the first one gets the user id.
     */
    public static function redeem(string $token, string $ip = ''): ?int
    {
        $token = trim($token);
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $hash = hash('sha256', $token);

        $pdo = Connection::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT id, user_id FROM login_links
                  WHERE token_hash = :h AND used_at IS NULL
                    AND expires_at > datetime("now")'
            );
            $st->execute(['h' => $hash]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->rollBack();
                return null;
            }
            $del = $pdo->prepare(
                'UPDATE login_links SET used_at = datetime("now") WHERE id = :i'
            );
            $del->execute(['i' => (int)$row['id']]);
            $pdo->commit();
            return (int)$row['user_id'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }

    /** Number of links that can still be used. */
    public static function activeCount(): int
    {
        return (int)Connection::value(
            'SELECT COUNT(*) FROM login_links
              WHERE used_at IS NULL AND expires_at > datetime("now")'
        );
    }

    /** Revoke all active links for one user (e.g. after a password change). */
    public static function revokeAll(int $userId): int
    {
        return Connection::run(
            'DELETE FROM login_links WHERE user_id = :u AND used_at IS NULL',
            ['u' => $userId]
        );
    }

    /**
     * Clean up expired / already-used links.
     *
     * Called by housekeeping: `used_at` rows need to be kept for a while so
     * reuse attempts can be told apart from wrong tokens, but they do not need
     * to be kept forever.
     */
    public static function prune(int $keepUsedDays = 7): int
    {
        return Connection::run(
            'DELETE FROM login_links
              WHERE (used_at IS NOT NULL AND used_at < datetime("now", :win))
                 OR (expires_at < datetime("now", :win2))',
            ['win' => '-' . $keepUsedDays . ' days', 'win2' => '-' . $keepUsedDays . ' days']
        )->rowCount();
    }
}
