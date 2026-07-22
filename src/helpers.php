<?php
declare(strict_types=1);

// Send a JSON response and stop.
function json(int $status, mixed $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Decode the JSON request body into an array (empty array if none/invalid).
function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Read the token from the "Authorization: Bearer <token>" header.
function bearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

// The logged-in player for this request, or null.
function currentPlayer(): ?array
{
    $token = bearerToken();
    if (!$token) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT p.* FROM sessions s
         JOIN players p ON p.id = s.player_id
         WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

// Like currentPlayer(), but sends 401 and stops if not logged in.
function requirePlayer(): array
{
    $player = currentPlayer();
    if (!$player) {
        json(401, ['error' => 'Not authenticated.']);
    }
    return $player;
}
