<?php
declare(strict_types=1);

/*
 * Curated identity for every monster: which PEOPLE it belongs to, what KIND of
 * thing it is, and (where the name implies one) which COUNTRY it comes from.
 *
 * The Dominions source data has no race or nation column — only boolean nature
 * flags (undead / animal / magicbeing / stonebeing / plant …). An earlier import
 * squeezed all of that into a single `race`, which is how 24 monsters ended up
 * as the "race" humanoid, and constructs and undead ended up as peoples. This
 * table is the hand-authored half that the CSVs cannot supply; the importer
 * reads it so a re-run never clobbers these values.
 *
 * Keyed by the monster's FINAL display name (after import.php renames it).
 *
 *   race   — the people who might one day hold an opinion of you. 'none' means
 *            there is no people here: a statue, a risen corpse, a walking vine.
 *            Relationships are not tracked for those (see Gob\Domain\Relationship).
 *   nature — what it is made of / animated by. Decides sparability, not race:
 *            mortal | beast | magical | undead | construct | plant.
 *   nation — the country it belongs to, or null. Only filled in where the name
 *            actually implies one; guessing a country for every wandering
 *            sorceress would be inventing lore for no gain. Nations are data
 *            only for now — standing is still tracked per race, so every human
 *            shares one reputation until we decide otherwise.
 */

return [
    // ── Seeded monsters (schema.sql, ids 1-6) ────────────────────────────────
    'Goblin Scout'                  => ['goblin',     'mortal',    null],
    'Gray Wolf'                     => ['wolf',       'beast',     null],
    'Road Bandit'                   => ['human',      'mortal',    null],   // outlaws: no country claims them
    'Cave Ogre'                     => ['ogre',       'mortal',    null],
    'Goblin Warlord'                => ['goblin',     'mortal',    null],
    'Crypt Guardian'                => ['none',       'construct', null],

    // ── Imported monsters (ids 1000+) ────────────────────────────────────────
    'Knight of the Unhallowed Tomb' => ['none',       'undead',    null],
    'Wolf'                          => ['wolf',       'beast',     null],
    'Wildwood Druid'                => ['human',      'mortal',    'wildlands'],
    'Mad Cultist'                   => ['human',      'mortal',    null],
    'Spider Lord'                   => ['spiderfolk', 'mortal',    'spider-kingdom'],
    'Sorceress'                     => ['human',      'mortal',    null],
    'Heavy Footman'                 => ['human',      'mortal',    'legion'],
    'Slave Mage'                    => ['human',      'mortal',    'sunken-city'],
    'Legion Standard'               => ['human',      'mortal',    'legion'],
    'Coastal Crossbowman'           => ['human',      'mortal',    'coast'],
    'Wizard of the Earth'           => ['human',      'mortal',    null],
    'Storm General'                 => ['human',      'mortal',    null],
    'Ashen Cultist'                 => ['human',      'mortal',    'ashlands'],
    'Beastborn'                     => ['beastkin',   'mortal',    null],
    'Seal Hunter'                   => ['human',      'mortal',    'frozen-north'],
    'Thunder Priest'                => ['human',      'mortal',    null],
    'Lobster Rider'                 => ['merfolk',    'mortal',    'sea-kingdom'],
    'Highland Sorceress'            => ['human',      'mortal',    'highlands'],
    'Meteorite Guard'               => ['human',      'mortal',    null],
    'Jungle Warrior'                => ['human',      'mortal',    'jungle'],
    'Warrior Smith'                 => ['dwarf',      'mortal',    'iron-lands'],
    'Knight of the Stone'           => ['human',      'mortal',    'iron-lands'],
    'Fay Knight'                    => ['fae',        'magical',   'isles'],
    'Serpent Maiden'                => ['naga',       'magical',   'serpent-realm'],
    'Temple Warden'                 => ['human',      'mortal',    'first-city'],
    'Bone Mother'                   => ['human',      'mortal',    'frozen-north'],
    'Clan Warriors'                 => ['human',      'mortal',    'green-isle'],
    'Armored Sacred Tiger'          => ['tiger',      'beast',     null],
    'Handmaiden of Death'           => ['none',       'undead',    null],
    'Tide Lord'                     => ['triton',     'mortal',    'sea-kingdom'],
    'Deepcave Sage'                 => ['olm',        'mortal',    'deep-caves'],
    'Living Pillar'                 => ['none',       'construct', 'first-city'],
    'Zealot'                        => ['giant',      'mortal',    'highlands'],
    'Undying'                       => ['giant',      'mortal',    null],
    'High Councilor'                => ['olm',        'mortal',    'deep-caves'],
    'Vine Ogre'                     => ['none',       'plant',     null],
    'Forest Giant'                  => ['giant',      'mortal',    null],
    'War Idol'                      => ['none',       'construct', null],
    'Solar Godling'                 => ['none',       'magical',   null],
    'Statue of War'                 => ['none',       'construct', null],
];
