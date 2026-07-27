<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Relationship;
use PDO;

// Persistence for the two-axis relationship model (§2). One table per scope,
// each holding both axes; a scope only gets a row once the player has actually
// done something at that scope — until then it inherits the broader one.
//
// The person scope (rel_npc) is deliberately absent: it needs persistent NPC
// identity (promotion of a spared individual), which is a later slice. Its
// blend weight is already reserved in Relationship::WEIGHTS.
final class RelationshipRepository
{
    public function __construct(private PDO $db) {}

    // The attitude an encounter actually uses: the weighted blend of every
    // scope, with unset scopes inheriting their parent.
    public function effective(int $playerId, string $race, ?int $provinceId, ?int $siteId): Relationship
    {
        $rows = $this->scopeRows($playerId, $race, $provinceId, $siteId);

        $hostility = [];
        $trust     = [];
        foreach ($rows as $scope => $row) {
            $hostility[$scope] = (int)$row['hostility'];
            $trust[$scope]     = (int)$row['trust'];
        }

        return new Relationship($race, Relationship::blend($hostility), Relationship::blend($trust));
    }

    // Generic-scope standing for every race the player has any history with —
    // the "what does the world think of me" list.
    public function known(int $playerId): array
    {
        $stmt = $this->db->prepare('SELECT race, hostility, trust FROM rel_generic WHERE player_id = ? ORDER BY race');
        $stmt->execute([$playerId]);
        return array_map(
            fn(array $r) => (new Relationship($r['race'], (int)$r['hostility'], (int)$r['trust']))->toArray(),
            $stmt->fetchAll()
        );
    }

    // Record a deed. It lands in full at the most specific scope the deed
    // happened at, then halves outward — word spreads, but weakly (§2).
    // $floor clamps Hostility from below, which is how sparing caps at Neutral.
    public function applyDeed(
        int $playerId,
        string $race,
        ?int $provinceId,
        ?int $siteId,
        int $hostilityDelta,
        int $trustDelta = 0,
        int $hostilityFloor = Relationship::MIN,
    ): void {
        $rows   = $this->scopeRows($playerId, $race, $provinceId, $siteId);
        $target = [];
        if ($siteId !== null)     $target[] = 'site';
        if ($provinceId !== null) $target[] = 'province';
        $target[] = 'generic';

        foreach ($target as $step => $scope) {
            $divisor = 2 ** $step;
            $h = (int)round($hostilityDelta / $divisor);
            $t = (int)round($trustDelta / $divisor);
            if ($h === 0 && $t === 0) {
                continue;
            }
            // Start from what this scope currently *reads as* (its own row, or
            // the value it inherits), so a first deed at a site doesn't reset
            // it to the race default.
            $newH = max($hostilityFloor, Relationship::clamp((int)$rows[$scope]['hostility'] + $h));
            $newT = Relationship::clamp((int)$rows[$scope]['trust'] + $t);
            $this->write($scope, $playerId, $race, $provinceId, $siteId, $newH, $newT);
        }
    }

    // Raw per-scope values with inheritance already resolved, keyed by scope.
    // Generic is the root and always resolves (to the race's starting stance).
    private function scopeRows(int $playerId, string $race, ?int $provinceId, ?int $siteId): array
    {
        $out = ['generic' => [
            'hostility' => Relationship::startingHostility($race),
            'trust'     => Relationship::START_TRUST,
        ]];

        $generic = $this->fetch(
            'SELECT hostility, trust FROM rel_generic WHERE player_id = ? AND race = ?',
            [$playerId, $race]
        );
        if ($generic) {
            $out['generic'] = $generic;
        }

        $out['province'] = $out['generic'];
        if ($provinceId !== null) {
            $row = $this->fetch(
                'SELECT hostility, trust FROM rel_province WHERE player_id = ? AND province_id = ? AND race = ?',
                [$playerId, $provinceId, $race]
            );
            if ($row) {
                $out['province'] = $row;
            }
        }

        $out['site'] = $out['province'];
        if ($siteId !== null) {
            $row = $this->fetch(
                'SELECT hostility, trust FROM rel_site WHERE player_id = ? AND site_id = ? AND race = ?',
                [$playerId, $siteId, $race]
            );
            if ($row) {
                $out['site'] = $row;
            }
        }

        return $out;
    }

    private function fetch(string $sql, array $args): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetch() ?: null;
    }

    private function write(
        string $scope,
        int $playerId,
        string $race,
        ?int $provinceId,
        ?int $siteId,
        int $hostility,
        int $trust,
    ): void {
        [$sql, $args] = match ($scope) {
            'site' => [
                'INSERT INTO rel_site (player_id, site_id, race, hostility, trust) VALUES (?, ?, ?, ?, ?)',
                [$playerId, $siteId, $race, $hostility, $trust],
            ],
            'province' => [
                'INSERT INTO rel_province (player_id, province_id, race, hostility, trust) VALUES (?, ?, ?, ?, ?)',
                [$playerId, $provinceId, $race, $hostility, $trust],
            ],
            default => [
                'INSERT INTO rel_generic (player_id, race, hostility, trust) VALUES (?, ?, ?, ?)',
                [$playerId, $race, $hostility, $trust],
            ],
        };
        $this->db->prepare($sql . ' ON DUPLICATE KEY UPDATE hostility = VALUES(hostility), trust = VALUES(trust)')
                 ->execute($args);
    }
}
