<?php
declare(strict_types=1);

namespace Gob\Domain;

// Who may create an account.
//
// The game is served on a public name while it is still being built, so the deployment
// sets GOB_INVITE_CODE and a stranger who finds the name can look at it but not register.
// A checkout with nothing configured stays open: a developer running the stack should not
// have to be let into their own game, and a gate that has to be switched on for local work
// is a gate that gets switched off and left off.
final class Invite
{
    // $configured is the deployment's code, $supplied is what the request carried.
    public static function accepts(?string $configured, ?string $supplied): bool
    {
        $configured = trim((string)$configured);
        if ($configured === '') {
            return true;
        }

        // hash_equals rather than ===: it compares every byte regardless of where the
        // first difference is, so the time taken says nothing about how much of the code
        // the caller already has right.
        return hash_equals($configured, trim((string)$supplied));
    }
}
