<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Domain\Item;
use Gob\Repositories;
use Gob\Repository\CharacterRepository;
use Gob\Repository\ItemRepository;
use Gob\Tests\Support\DatabaseTestCase;

// Owning, wearing and spending items. The rule that matters most is that
// equipping never silently throws away gear the player chose.
final class ItemRepositoryTest extends DatabaseTestCase
{
    private ItemRepository $repo;
    private int $player;
    private int $charId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = Repositories::get(ItemRepository::class);
        $this->player = $this->makePlayer('owner');
        $this->charId = Repositories::get(CharacterRepository::class)->ensure($this->player, 'Gareth');
        // Start from a bare hero so the starter kit does not fill the slots
        // a test is about to reason over.
        $this->db->prepare('DELETE FROM character_items WHERE character_id = ?')->execute([$this->charId]);
    }

    private function definitionOfType(string $slotType): int
    {
        $stmt = $this->db->prepare('SELECT id FROM items WHERE slot_type = ? AND kind = "gear" ORDER BY id LIMIT 1');
        $stmt->execute([$slotType]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, "the catalogue has no $slotType to test with");
        return (int)$id;
    }

    private function slotOf(int $charItemId): ?string
    {
        $stmt = $this->db->prepare('SELECT equipped_slot FROM character_items WHERE id = ?');
        $stmt->execute([$charItemId]);
        return $stmt->fetchColumn() ?: null;
    }

    public function testAGrantedItemLandsInTheBackpackUnworn(): void
    {
        $ci = $this->repo->grant($this->charId, $this->definitionOfType('weapon'));

        $this->assertNull($this->slotOf($ci));
        $this->assertCount(1, $this->repo->owned($this->charId));
    }

    public function testAnOwnedItemCarriesItsDefinitionAlongside(): void
    {
        $itemId = $this->definitionOfType('weapon');
        $this->repo->grant($this->charId, $itemId);

        $owned = $this->repo->owned($this->charId)[0];

        $this->assertSame($itemId, $owned['item_id']);
        $this->assertSame($this->repo->name($itemId), $owned['name']);
        $this->assertArrayHasKey('bonuses', $owned);
    }

    public function testEquippingPutsTheItemInItsSlot(): void
    {
        $ci = $this->repo->grant($this->charId, $this->definitionOfType('weapon'));

        $this->repo->equipIfFree($this->charId, $ci);

        $this->assertSame('weapon', $this->slotOf($ci));
    }

    // Auto-equipping is for filling empty hands. It must never replace
    // something the player deliberately put on.
    public function testAutoEquippingNeverReplacesGearTheHeroIsAlreadyWearing(): void
    {
        $itemId = $this->definitionOfType('weapon');
        $worn   = $this->repo->grant($this->charId, $itemId);
        $spare  = $this->repo->grant($this->charId, $itemId);

        $this->repo->equipIfFree($this->charId, $worn);
        $this->repo->equipIfFree($this->charId, $spare);

        $this->assertSame('weapon', $this->slotOf($worn));
        $this->assertNull($this->slotOf($spare), 'the second one stayed in the backpack');
    }

    // Rings and bracelets come in pairs, so the second one has somewhere to go.
    public function testASecondRingFindsTheOtherHand(): void
    {
        $ring  = $this->definitionOfType('ring');
        $left  = $this->repo->grant($this->charId, $ring);
        $right = $this->repo->grant($this->charId, $ring);
        $third = $this->repo->grant($this->charId, $ring);

        $this->repo->equipIfFree($this->charId, $left);
        $this->repo->equipIfFree($this->charId, $right);
        $this->repo->equipIfFree($this->charId, $third);

        $this->assertSame('ring_1', $this->slotOf($left));
        $this->assertSame('ring_2', $this->slotOf($right));
        $this->assertNull($this->slotOf($third));
    }

    public function testAPotionIsNeverWorn(): void
    {
        $stmt = $this->db->query('SELECT id FROM items WHERE kind = "consumable" ORDER BY id LIMIT 1');
        $potion = $stmt->fetchColumn();
        if ($potion === false) {
            $this->markTestSkipped('the catalogue has no consumable to test with');
        }
        $ci = $this->repo->grant($this->charId, (int)$potion);

        $this->repo->equipIfFree($this->charId, $ci);

        $this->assertNull($this->slotOf($ci));
    }

    // Deliberately equipping is the opposite: it does replace, in one step, so
    // the slot never holds two things or briefly none.
    public function testDeliberatelyEquippingReplacesWhatWasThere(): void
    {
        $itemId = $this->definitionOfType('weapon');
        $first  = $this->repo->grant($this->charId, $itemId);
        $second = $this->repo->grant($this->charId, $itemId);
        $this->repo->equip($this->charId, $first, 'weapon');

        $this->repo->equip($this->charId, $second, 'weapon');

        $this->assertNull($this->slotOf($first));
        $this->assertSame('weapon', $this->slotOf($second));
        $this->assertSame(1, (int)$this->db->query(
            "SELECT COUNT(*) FROM character_items WHERE character_id = {$this->charId} AND equipped_slot = 'weapon'"
        )->fetchColumn());
    }

    public function testTakingSomethingOffLeavesTheSlotEmptyAndTheItemOwned(): void
    {
        $ci = $this->repo->grant($this->charId, $this->definitionOfType('weapon'));
        $this->repo->equip($this->charId, $ci, 'weapon');

        $this->repo->unequipSlot($this->charId, 'weapon');

        $this->assertNull($this->slotOf($ci));
        $this->assertCount(1, $this->repo->owned($this->charId));
    }

    public function testTheFirstFreeSlotIsTheFirstOneNotTaken(): void
    {
        $ring = $this->definitionOfType('ring');
        $slots = Item::slotsForType('ring');

        $this->assertSame('ring_1', $this->repo->firstFreeSlot($this->charId, $slots));

        $this->repo->equip($this->charId, $this->repo->grant($this->charId, $ring), 'ring_1');
        $this->assertSame('ring_2', $this->repo->firstFreeSlot($this->charId, $slots));

        $this->repo->equip($this->charId, $this->repo->grant($this->charId, $ring), 'ring_2');
        $this->assertNull($this->repo->firstFreeSlot($this->charId, $slots));
    }

    public function testAnInstanceIsOnlyVisibleToItsOwner(): void
    {
        $ci = $this->repo->grant($this->charId, $this->definitionOfType('weapon'));
        $thief = Repositories::get(CharacterRepository::class)->ensure($this->makePlayer('thief'), 'Thief');

        $this->assertNotNull($this->repo->instance($ci, $this->charId));
        $this->assertNull($this->repo->instance($ci, $thief));
    }

    public function testProofItemsAreCountedAcrossEveryInstanceOwned(): void
    {
        $ear = $this->definitionOfType('weapon');
        $this->repo->grant($this->charId, $ear);
        $this->repo->grant($this->charId, $ear);
        $this->repo->grant($this->charId, $ear);

        $this->assertSame(3, $this->repo->countForPlayer($this->player, $ear));
    }

    public function testTurningInProofConsumesExactlyWhatWasAsked(): void
    {
        $ear = $this->definitionOfType('weapon');
        for ($i = 0; $i < 5; $i++) {
            $this->repo->grant($this->charId, $ear);
        }

        $this->repo->consume($this->charId, $ear, 3);

        $this->assertSame(2, $this->repo->countForPlayer($this->player, $ear));
    }

    public function testConsumingMoreThanIsOwnedTakesWhatThereIsWithoutFailing(): void
    {
        $ear = $this->definitionOfType('weapon');
        $this->repo->grant($this->charId, $ear);

        $this->repo->consume($this->charId, $ear, 5);

        $this->assertSame(0, $this->repo->countForPlayer($this->player, $ear));
    }

    public function testDroppingAnInstanceRemovesOnlyThatOne(): void
    {
        $itemId = $this->definitionOfType('weapon');
        $keep   = $this->repo->grant($this->charId, $itemId);
        $drop   = $this->repo->grant($this->charId, $itemId);

        $this->repo->deleteInstance($drop);

        $this->assertNotNull($this->repo->instance($keep, $this->charId));
        $this->assertNull($this->repo->instance($drop, $this->charId));
    }

    public function testTheLootTableIsTheWholeCatalogue(): void
    {
        $definitions = $this->repo->allDefinitions();

        $this->assertNotEmpty($definitions);
        $this->assertSame(
            (int)$this->db->query('SELECT COUNT(*) FROM items')->fetchColumn(),
            count($definitions),
        );
    }

    public function testARewardOfAGivenRarityIsAlwaysWearableGear(): void
    {
        $id = $this->repo->randomByRarity('common');

        $this->assertNotNull($id);
        $stmt = $this->db->prepare('SELECT kind, rarity FROM items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $this->assertSame('gear', $row['kind']);
        $this->assertSame('common', $row['rarity']);
    }

    public function testARarityNothingIsAuthoredAtRewardsNothing(): void
    {
        $this->assertNull($this->repo->randomByRarity('mythic'));
    }
}
