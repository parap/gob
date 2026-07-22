<?php
declare(strict_types=1);

// A random 64-hex-char session token.
function makeToken(): string
{
    return bin2hex(random_bytes(32));
}

// Create a session row valid for 30 days and return its token.
function issueSession(int $playerId): string
{
    $token = makeToken();
    $stmt  = db()->prepare(
        'INSERT INTO sessions (token, player_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $stmt->execute([$token, $playerId]);
    return $token;
}

function handleRegister(): void
{
    $b        = body();
    $username = trim((string)($b['username'] ?? ''));
    $email    = trim((string)($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');

    if (strlen($username) < 3 || strlen($username) > 32) {
        json(400, ['error' => 'Username must be 3–32 characters.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json(400, ['error' => 'Invalid email address.']);
    }
    if (strlen($password) < 6) {
        json(400, ['error' => 'Password must be at least 6 characters.']);
    }

    $db = db();

    $stmt = $db->prepare('SELECT id FROM players WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        json(409, ['error' => 'Username or email already taken.']);
    }

    $stmt = $db->prepare(
        'INSERT INTO players (username, email, password_hash) VALUES (?, ?, ?)'
    );
    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $playerId = (int)$db->lastInsertId();

    createStartingSettlement($playerId);
    ensureCharacter($playerId, $username);

    $token = issueSession($playerId);
    json(201, ['id' => $playerId, 'username' => $username, 'token' => $token]);
}

function handleLogin(): void
{
    $b        = body();
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM players WHERE username = ?');
    $stmt->execute([$username]);
    $player = $stmt->fetch();

    if (!$player || !password_verify($password, $player['password_hash'])) {
        json(401, ['error' => 'Invalid username or password.']);
    }

    $token = issueSession((int)$player['id']);
    json(200, [
        'id'       => (int)$player['id'],
        'username' => $player['username'],
        'token'    => $token,
    ]);
}

function handleLogout(): void
{
    $token = bearerToken();
    if ($token) {
        $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
        $stmt->execute([$token]);
    }
    json(200, ['ok' => true]);
}
