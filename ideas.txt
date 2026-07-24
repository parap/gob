========================================================================
GOB — DESIGN IDEAS & NOTES
========================================================================
Source: ChatGPT brainstorm (parsed 2026-07-24), in the spirit of
"Mother of Learning" ("повторение — мать учения" / Zorian).
This is an idea backlog, NOT a spec. Nothing here is committed.

------------------------------------------------------------------------
CORE CONCEPT
------------------------------------------------------------------------
The strong seed is NOT "monsters can talk" (been done). It is:

    The player starts with a WRONG MODEL OF THE WORLD and gradually
    becomes able to perceive a more complex reality.

- First hours = ordinary RPG (kill monsters, grab loot).
- Later you realize ~80% of "enemies" were defending themselves, most
  "monsters" have families, some human villages are worse than orcs,
  and the real villains rarely hold a sword.
- Progression changes WHAT THE PLAYER CAN SEE AND DO — not +5 STR.
  "I now know something I didn't, so I can do what was impossible before."
- The pleasure is the "I used to be blind" feeling: each new level of
  knowledge reframes everything that happened earlier.

------------------------------------------------------------------------
1. LAYERS OF REALITY PER LOCATION  (central technical trick)
------------------------------------------------------------------------
No new maps — the SAME location, with content gated by what the hero
can perceive. Each place has layers:

  Layer 1 Barbarian  : Enemy / Loot / Trap / Boss
  Layer 2 Explorer   : Tracks / Families / History / Resources
  Layer 3 Diplomat   : Names / Politics / Conflicts / Requests
  Layer 4 Ally       : Secrets / Ancient knowledge / Unique quests

Implemented as simple gates:
  if lore_goblin     >= 5: show_hidden_room()
  if language_goblin >= 3: enable_dialogue()
  if empathy         >= 4: show_emotional_context()

SAME cave over time (content unchanged, only ACCESS changes):
  start   : 40 enemies
  +10h    : 15 traders / 6 children / 4 shamans / 8 warriors
  +20h    : you know their names
  +30h    : invited to the harvest festival
  +40h    : asked to judge a dispute between two families

------------------------------------------------------------------------
2. FIRST CONTACT SHOULD BE EARNED (and ideally monster-initiated)
------------------------------------------------------------------------
Key design problem: if you MUST kill to unlock talking, that's absurd
("to befriend goblins, first slaughter 200 goblins"). Fix:

Three independent scales, first contact only when all cross a threshold:
  Mercy      — you don't finish off the downed
  Curiosity  — you inspect/study instead of just looting
  Language   — you learn words (e.g. from neutral NPCs: wolves, fairies,
               spirits, birds — these teach the first words)

Then a shaman steps forward with a white flag — an EARNED moment, not a
skill-tree checkbox. Best framing: the MONSTERS decide to try talking,
because the player behaved strangely (e.g. spared the downed 5x).
Big RPG mistake = player is always the initiator; more interesting is
the reverse.

Purist / most Mother-of-Learning option:
  Combat can NEVER unlock the social branch. The only path is behavior
  that makes the other side reconsider you. The player changes their
  PLAY STYLE, not just character stats.

Learning ladder for neutral->hostile races:
  Animals -> Wolves -> Goblins -> Orcs -> Demons

------------------------------------------------------------------------
3. NOT EVERYONE IN A LAIR IS THE SAME
------------------------------------------------------------------------
  Raiders   30%  — actually attack
  Hunters   40%  — defend territory
  Civilians 30%  — flee

After winning a fight:  Kill / Leave alive / Treat wounds
Leaving alive / treating wounds is what starts relationships.

------------------------------------------------------------------------
4. COMPACT, REUSABLE CONTENT SYSTEM  (the practical MVP answer)
------------------------------------------------------------------------
Don't build a huge tree. 5 UNIVERSAL SKILLS that work on all races ->
content multiplies automatically.

5 skills:
  1. Linguistics  0 grrr / 1 words / 2 simple sentences / 3 fluent / 4 ancient tongue
  2. Empathy      1 fear / 2 hunger / 3 respect / 4 lies / 5 intentions
  3. Survival     bandages / food / poison / disease / hunting (help creatures)
  4. Lore         per-race history (wolves / goblins / undead / demons)
  5. Reputation   NOT leveled by hand — just how the race sees you

5 relationship stages per race:
  0 Monster -> 1 Curious -> 2 Neutral -> 3 Friendly -> 4 Ally
Each stage unlocks: new dialogue / quests / goods / abilities.

Tiered quest TEMPLATES shared by all races (same structure, diff text):
  Tier 1: kill / fetch / repair / find
  Tier 2: save a child / heal the sick / catch a thief / find the lost
  Tier 3: settle a dispute / reconcile clans / solve a murder / exile a traitor
  Tier 4: change policy / forge alliance / depose a chief / stop a war
Example chains:
  Goblins: find mushroom -> find child -> solve murder -> forge alliance
  Wolves : bring meat   -> find cub   -> find pack-killer -> unite packs
  Demons : bring souls  -> save heir  -> uncover plot   -> elect new archdemon

Reward = KNOWLEDGE, not gold:  Wolf culture +1 / Goblin history +2 /
Necromancy +1.  Knowledge unlocks new dialogue options.

x100 content via PROFESSIONS (not hand-authored quests):
  Tag each NPC: Hunter / Shaman / Chief / Merchant / Child / Guard / Farmer
  Each auto-generates requests from a NEEDS list:
    Food / Medicine / Protection / Knowledge / Revenge / Mate / Religion
  e.g. Hunter: needs meat / needs arrows / helper killed / dog missing
       Shaman: needs herbs / rare skull / spirits angry / chief is sick
       Merchant: caravan lost / short on goods / needs a guard

MVP-in-a-month scope: 3 races (wolves, goblins, undead) x 5 professions
x 4 trust levels = dozens of quests with zero unique scripting.

------------------------------------------------------------------------
5. BUILD AROUND AN INFORMATION MODEL, NOT A SKILL TREE
------------------------------------------------------------------------
If it's just "leveled Speech = new lines", the player decodes it and the
magic dies. Instead, each NPC has KNOWLEDGE TIERS; the engine decides
what to reveal from the hero's stats.

  id: wolf01
  knowledge:
    public:   [hostile, hungry]
    observed: [has_pups]
    trusted:  [hunting_ground_destroyed]
    intimate: [asks_help]

  Player: Perception=12  Empathy=5  Language(Wolf)=2  Trust(wolf)=30

Same wolf, four different encounters:
  novice        : Wolf / HP 35 / Growl
  +Perception   : Wolf / Hungry / Growl
  +Empathy      : Wolf / Hungry / Scared
  +Language     : Wolf / "We don't want trouble."
  +Trust        : Wolf / "Our pups are starving."

Dialogue = blocks, each with requirements:
  Greeting -> Need -> Secrets -> Quest -> Personal
  (e.g. Need requires Trust 10 + Language 2 + Empathy 4)

Talk gate can be OR-composed:
  Talk requires (Language>=3 AND Trust>=20) OR AnimalFriendship>=5

------------------------------------------------------------------------
6. RICHER RELATIONSHIP MODEL (Crusader Kings style)
------------------------------------------------------------------------
Instead of a single Trust number, track:
  Opinion / Fear / Respect / Gratitude / Curiosity / Hatred / Debt
  e.g. Wolf before: Fear 80 / Trust 5  / Respect 20
       after rescue: Fear 20 / Trust 60 / Respect 80

------------------------------------------------------------------------
7. SKILLS AS NEW "SENSES" (perception checks that inject info)
------------------------------------------------------------------------
Not "+10 damage" — new pieces of information:
  Botany 5   -> (These berries are poisonous.)
  Medicine 8 -> (He is pretending to be injured.)
  Politics 12-> (This village chief is lying.)
Empathy on a lord saying "I like you":
  low  : "I like you."
  high : "I like you." (He's lying.)
  max  : "I like you." (He fears his neighbour.)(He wants your army.)
         (He doesn't care about you.)

"World model" / Tactics skill — levels up what the PLAYER understands,
not the hero:
  Tactics 1: "3 orcs."
  Tactics 4: "3 orcs. Archer. Commander. Recruit."
  Tactics 8: "If you attack from here they retreat there. Win chance 73%."

------------------------------------------------------------------------
8. COMBINATORIAL PROGRESSION (beyond Mother of Learning)
------------------------------------------------------------------------
Breakthroughs come from COMBINING branches, not maxing one:
  Goblin language + Goblin history -> ancient songs
  Ancient songs   + Music          -> calm spirits
  Spirits         + Alchemy        -> lost elixir recipe
  Elixir          + Medicine       -> cure the king
  Cured king                        -> access to the sealed archive

------------------------------------------------------------------------
9. FULL SKILL CATALOGUE (floated, pick a subset)
------------------------------------------------------------------------
  1. Language      emotions -> words -> conversation -> fluent
  2. Empathy       fear -> anger -> lies -> true motives
  3. Observation   tracks -> camps -> secret passages -> event-traces -> place history
  4. Diplomacy     request -> persuade -> negotiate -> compromise -> alliances
  5. Reputation    monsters know you: "Human!" -> "The butcher!" / "The one who always talks"
  6. Biology       study creatures: (this dragon is hungry / sick / 800 yrs old / never strikes first)
  7. History        "everyone thinks orcs are evil" -> "humans expelled them" -> "elves started the war" -> "everyone was wrong"
  8. Culture       customs -> traditions -> religion -> humor -> taboo (stop accidentally insulting NPCs)
  9. Disguise      clothing -> behavior -> accent -> smell -> full acceptance (live among monsters)
 10. Trust         gated by number of encounters: 1 / 5 / 20 / 50
                   "Go away." -> "Thanks." -> "Need help." -> "We never told humans this story."

------------------------------------------------------------------------
10. MEMORY / RUMOR SYSTEM
------------------------------------------------------------------------
Player-known facts have a state:
  Remembered -> Verified -> Shared -> Common knowledge
Telling a secret (e.g. "the king killed the prince", Shared=false) can
actually change the world.

Contradictory accounts — truth is not given, it's reconstructed:
  NPC A: "Humans murdered us."
  NPC B: "Humans defended themselves."
  Truth resolves only with: History 8 + Archives + Witnesses + Empathy

------------------------------------------------------------------------
CORE GAMEPLAY LOOP
------------------------------------------------------------------------
  explore village -> find NPC -> can't talk -> go train -> return ->
  new dialogue -> new quest -> new ability -> new area -> new race ->
  new language -> return to first village -> half the old NPCs now give
  completely different quests.
=> "the world unfolds" feeling.

Also: replay same content with new capabilities (Mother of Learning's
strongest mechanic):
  door locked -> lockpick -> teleport -> guard knows you -> you know the
  password -> you arrive 3 days before the events. Same content, played
  entirely differently.

------------------------------------------------------------------------
MAPPING TO CURRENT gob CODEBASE (notes, not decisions)
------------------------------------------------------------------------
- character_skills already stores skills as VARCHAR (extensible) — fits
  Linguistics/Empathy/Survival/Lore/etc. without migrations.
- monsters already have race / alignment / monster_tags — good basis for
  per-race relationship stages and Lore.
- Province/site world already gates discovery on min_perception — the
  "layers of reality" gating is the same pattern generalized to
  skill thresholds per site/NPC.
- NEW tables likely needed: race_relations (per player+race: stage +
  opinion/fear/respect/...), knowledge (per player: topic -> value),
  npc profession + needs, dialogue blocks with requirements.
- Reward pivot: quests grant Knowledge (unlocks dialogue) rather than
  only gold/loot.
