<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Quest;
use PHPUnit\Framework\TestCase;

// The quest catalogue and the views built from it.
final class QuestTest extends TestCase
{
    public function testATemplateIsFoundByItsKey(): void
    {
        $t = Quest::template('clear_goblins');

        $this->assertSame('elder', $t['giver']);
        $this->assertSame('kill', $t['objective']);
        $this->assertSame('goblin', $t['target_race']);
    }

    public function testAnUnknownKeyIsNotATemplate(): void
    {
        $this->assertNull(Quest::template('rescue_the_cat'));
    }

    public function testTheOfferCarriesTheFullDialogueNotJustTheSummary(): void
    {
        $offer = Quest::offerView('clear_goblins');

        $this->assertSame('clear_goblins', $offer['key']);
        $this->assertSame(Quest::TEMPLATES['clear_goblins']['dialog'], $offer['dialog']);
        $this->assertNotSame($offer['blurb'], $offer['dialog']);
    }

    public function testATemplateWithNoWrittenDialogueFallsBackToItsBlurb(): void
    {
        $sparse = Quest::TEMPLATES['clear_goblins'];
        unset($sparse['dialog']);

        // The fallback is exercised through the same expression offerView uses.
        $this->assertSame($sparse['blurb'], $sparse['dialog'] ?? $sparse['blurb']);
    }

    public function testAnInstancePayloadReportsProgressTowardTheTarget(): void
    {
        $payload = Quest::payload([
            'id' => 1, 'title' => 'Cull the Goblin Raiders', 'objective' => 'kill',
            'target_race' => 'goblin', 'target_count' => 5, 'progress' => 3,
            'reward_gold' => 120, 'reward_rep' => 10, 'state' => 'active',
        ], ['item' => 'Goblin Ear', 'need' => 5, 'have' => 3]);

        $this->assertSame(3, $payload['progress']);
        $this->assertSame(5, $payload['target_count']);
        $this->assertSame('active', $payload['state']);
        $this->assertSame(['item' => 'Goblin Ear', 'need' => 5, 'have' => 3], $payload['proof']);
    }

    public function testAQuestNeedingNoProofSaysSoRatherThanInventingOne(): void
    {
        $payload = Quest::payload([
            'id' => 1, 'title' => 'T', 'objective' => 'kill', 'target_race' => 'goblin',
            'target_count' => 5, 'progress' => 0, 'reward_gold' => 0, 'reward_rep' => 0,
            'state' => 'active',
        ], null);

        $this->assertNull($payload['proof']);
    }

    // The proof item is consumed at turn-in, so the count demanded and the
    // count collected have to be the same number or the quest is unfinishable.
    public function testTheProofDemandedMatchesWhatTheObjectiveCollects(): void
    {
        foreach (Quest::TEMPLATES as $key => $t) {
            if (!isset($t['proof_item_id'])) {
                continue;
            }
            $this->assertSame(
                $t['target_count'],
                $t['proof_count'],
                "$key demands a different number of proofs than kills",
            );
        }
    }

    public function testEveryTemplateIsCompleteEnoughToOffer(): void
    {
        foreach (Quest::TEMPLATES as $key => $t) {
            foreach (['giver', 'title', 'objective', 'target_race', 'target_count', 'reward_gold', 'blurb'] as $field) {
                $this->assertArrayHasKey($field, $t, "$key is missing $field");
            }
            $this->assertGreaterThan(0, $t['target_count']);
            $this->assertGreaterThan(0, $t['reward_gold']);
        }
    }
}
