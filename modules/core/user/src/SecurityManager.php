<?php

declare(strict_types=1);

namespace Module\User;

use Framework\Security\AccessDeniedException;
use Framework\Security\IdentityInterface;
use Framework\Security\RequestSecurityInterface;
use Framework\Security\CsrfInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityManager implements RequestSecurityInterface, CsrfInterface
{
    private ?string $sessionId = null;
    private ?string $csrf = null;
    private ?Account $account = null;
    private bool $newCookie = false;
    private readonly string $dummyHash;

    public function __construct(private readonly AccountManager $accounts, private readonly string $workspace)
    {
        $this->dummyHash = password_hash('unavailable-account', PASSWORD_DEFAULT);
    }

    public function begin(Request $request): void
    {
        $this->end();
        $candidate = $request->cookies->all()[$this->cookieName()] ?? null;
        if (is_string($candidate) && strlen($candidate) === 64 && preg_match('/^[a-f0-9]{64}$/', $candidate)) {
            $row = $this->accounts->database()->queryOne('SELECT user_id, csrf_token FROM auth_sessions WHERE id_hash = :id AND expires_at > :now', ['id' => hash('sha256', $candidate), 'now' => time()]);
            if ($row !== null) {
                $this->sessionId = $candidate;
                $this->csrf = $row['csrf_token'];
                if ($row['user_id'] !== null) {
                    $account = $this->accounts->find((int) $row['user_id']);
                    if ($account !== null && $account->active) {
                        $this->account = $account;
                    }
                }
                return;
            }
        }
        $this->createSession(null);
    }

    public function finish(Response $response, Request $request): void
    {
        if ($this->newCookie && $this->sessionId !== null) {
            $response->headers->setCookie(Cookie::create($this->cookieName())
                ->withValue($this->sessionId)
                ->withExpires(time() + 7200)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX));
        }
        $response->headers->set('Cache-Control', 'private, no-store');
    }

    public function end(): void
    {
        $this->sessionId = null;
        $this->csrf = null;
        $this->account = null;
        $this->newCookie = false;
    }

    public function identity(): ?IdentityInterface
    {
        return $this->account;
    }

    public function active(): bool
    {
        return $this->sessionId !== null;
    }

    public function can(string $permission): bool
    {
        return $this->account !== null && $this->accounts->hasPermission($this->account->id(), $permission);
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new AccessDeniedException('Access denied.');
        }
    }

    public function csrfToken(): string
    {
        return $this->csrf ?? throw new \LogicException('No active request session.');
    }

    public function validateCsrf(mixed $token): bool
    {
        return is_string($token) && $this->csrf !== null && hash_equals($this->csrf, $token);
    }

    public function login(string $email, string $password, string $clientIp): bool
    {
        $db = $this->accounts->database();
        $key = hash('sha256', strtolower(trim($email)) . '|' . $clientIp);
        $row = $db->queryOne('SELECT attempts, window_start FROM login_attempts WHERE key_hash = :key', ['key' => $key]);
        $now = time();
        if ($row !== null && (int) $row['window_start'] + 900 > $now && (int) $row['attempts'] >= 5) {
            return false;
        }
        $credentials = $this->accounts->credentials($email);
        $valid = password_verify($password, $credentials['password_hash'] ?? $this->dummyHash);
        if (!$valid || $credentials === null || !(bool) $credentials['active']) {
            if ($row === null) {
                $db->execute('INSERT INTO login_attempts (key_hash, attempts, window_start) VALUES (:key, 1, :start)', ['key' => $key, 'start' => $now]);
            } elseif ((int) $row['window_start'] + 900 <= $now) {
                $db->execute('UPDATE login_attempts SET attempts = 1, window_start = :start WHERE key_hash = :key', ['key' => $key, 'start' => $now]);
            } else {
                $db->execute('UPDATE login_attempts SET attempts = attempts + 1 WHERE key_hash = :key', ['key' => $key]);
            }
            return false;
        }
        $db->execute('DELETE FROM login_attempts WHERE key_hash = :key', ['key' => $key]);
        $this->rotate((int) $credentials['id']);
        $this->account = $this->accounts->find((int) $credentials['id']);
        return true;
    }

    public function logout(): void
    {
        $this->rotate(null);
        $this->account = null;
    }

    private function rotate(?int $userId): void
    {
        if ($this->sessionId !== null) {
            $this->accounts->database()->execute('DELETE FROM auth_sessions WHERE id_hash = :id', ['id' => hash('sha256', $this->sessionId)]);
        }
        $this->createSession($userId);
    }

    private function createSession(?int $userId): void
    {
        $this->sessionId = bin2hex(random_bytes(32));
        $this->csrf = bin2hex(random_bytes(32));
        $this->newCookie = true;
        $this->accounts->database()->execute('INSERT INTO auth_sessions (id_hash, user_id, csrf_token, expires_at) VALUES (:id, :user, :csrf, :expires)', [
            'id' => hash('sha256', $this->sessionId), 'user' => $userId, 'csrf' => $this->csrf, 'expires' => time() + 7200,
        ]);
    }

    private function cookieName(): string
    {
        return 'fw_session_' . $this->workspace;
    }
}
