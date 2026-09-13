<?php
declare(strict_types=1);

namespace Gob\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

// Base for tests that go through the running application over HTTP.
//
// Everything reachable in-process is tested in-process; this exists for the
// behaviour that is not. A handler answering through json() calls exit, so a
// refusal — the study lock, an unauthenticated request — can only be observed
// from outside the process that would be killed by it.
//
// These talk to the development database, because that is the one the running
// app is configured with. Each test therefore registers a player of its own and
// deletes it afterwards; nothing else is touched. Set GOB_BASE_URL to point
// them elsewhere. If the app is not up, the tests skip rather than fail: a
// checkout with no containers running is not a broken suite.
abstract class ApiTestCase extends TestCase
{
    protected string $base;
    private ?PDO $live = null;
    /** @var string[] usernames registered by this test */
    private array $registered = [];

    protected function setUp(): void
    {
        $this->base = rtrim(getenv('GOB_BASE_URL') ?: 'http://caddy', '/');

        $probe = $this->request('GET', '/api/');
        if ($probe['status'] === 0) {
            $this->markTestSkipped("The application is not answering at {$this->base} — start the stack to run these.");
        }
    }

    protected function tearDown(): void
    {
        if ($this->registered === []) {
            return;
        }
        $db = $this->liveDb();
        $stmt = $db->prepare('DELETE FROM players WHERE username = ?');
        foreach ($this->registered as $username) {
            $stmt->execute([$username]);
        }
        $this->registered = [];
    }

    // Register a throwaway player and return their bearer token.
    protected function newPlayer(): string
    {
        $username = 'apitest_' . bin2hex(random_bytes(5));
        $this->registered[] = $username;

        $res = $this->request('POST', '/api/auth/register', [
            'username' => $username,
            'email'    => "$username@example.test",
            'password' => 'secret123',
        ]);
        $this->assertSame(201, $res['status'], 'registration failed: ' . $res['raw']);

        return (string)$res['body']['token'];
    }

    /** @return array{status:int, body:array, raw:string} */
    protected function request(string $method, string $path, ?array $payload = null, ?string $token = null): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = "Authorization: Bearer $token";
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw    = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => json_decode($raw, true) ?: [], 'raw' => $raw];
    }

    // The database the running app writes to — used only to clean up after a
    // test and to set up a state the API has no route for.
    protected function liveDb(): PDO
    {
        return $this->live ??= new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                (int)(getenv('DB_PORT') ?: 3306),
                getenv('DB_NAME') ?: 'gob',
            ),
            getenv('DB_USER') ?: 'gob',
            getenv('DB_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    protected function characterIdOf(string $token): int
    {
        $me = $this->request('GET', '/api/character/me', null, $token);
        $this->assertSame(200, $me['status'], $me['raw']);
        return (int)$me['body']['id'];
    }
}
