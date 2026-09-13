<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Invite;
use PHPUnit\Framework\TestCase;

// Who is allowed to create an account. The game is served on a public name while it is
// still being built, so the deployment sets a code and a stranger who finds the name can
// look but not register. A checkout with no code configured stays open, because a
// developer running the stack should not have to be let into their own game.
final class InviteTest extends TestCase
{
    public function testNoCodeConfiguredLetsAnyoneIn(): void
    {
        $this->assertTrue(Invite::accepts(null, null));
        $this->assertTrue(Invite::accepts(null, 'whatever'));
    }

    // An empty string is what an unset variable looks like once it has been through
    // getenv() and a cast, so it must mean the same thing as absent. Reading it as "the
    // code is the empty string" would gate the game behind a code nobody can send.
    public function testAnEmptyCodeIsTheSameAsNoneAtAll(): void
    {
        $this->assertTrue(Invite::accepts('', null));
        $this->assertTrue(Invite::accepts('   ', 'anything'));
    }

    public function testAConfiguredCodeAdmitsOnlyThatCode(): void
    {
        $this->assertTrue(Invite::accepts('let-me-in', 'let-me-in'));
        $this->assertFalse(Invite::accepts('let-me-in', 'let-me-IN'));
        $this->assertFalse(Invite::accepts('let-me-in', 'wrong'));
        $this->assertFalse(Invite::accepts('let-me-in', null));
        $this->assertFalse(Invite::accepts('let-me-in', ''));
    }

    // Surrounding whitespace comes from copying the code out of a chat message, and
    // refusing it teaches nothing to the person who did it.
    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $this->assertTrue(Invite::accepts('let-me-in', '  let-me-in '));
        $this->assertTrue(Invite::accepts(' let-me-in ', 'let-me-in'));
    }

    // A prefix must not be enough. Comparing with a function that stops at the first
    // difference also leaks the code's length and content through timing, so the check
    // uses hash_equals; this asserts the behaviour that would go first if it did not.
    public function testAPrefixIsNotEnough(): void
    {
        $this->assertFalse(Invite::accepts('let-me-in', 'let'));
        $this->assertFalse(Invite::accepts('let-me-in', 'let-me-in-and-more'));
    }
}
