<?php

namespace App\Services;

use App\Models\FeatureSwitch;
use App\Models\Hotel;
use Illuminate\Support\Collection;

/**
 * Resolves Super Admin switches for a hotel: one hotel → its plan → everyone → default.
 * The most specific setting wins. Results are kept for the rest of the request.
 */
class Switches
{
    /** @var array<int, array<string, array{value: mixed, source: string}>> */
    private array $memo = [];

    /** @return array<string, array{type: string, group: string, default: mixed}> */
    public static function registry(): array
    {
        return config('switches');
    }

    public function value(?Hotel $hotel, string $key): mixed
    {
        return $this->resolve($hotel, $key)['value'];
    }

    public function on(?Hotel $hotel, string $key): bool
    {
        return (bool) $this->value($hotel, $key);
    }

    /** @return array{value: mixed, source: string} source: hotel, plan, everyone, default */
    public function resolve(?Hotel $hotel, string $key): array
    {
        $all = $this->all($hotel);

        return $all[$key] ?? ['value' => self::registry()[$key]['default'] ?? null, 'source' => 'default'];
    }

    /** @return array<string, array{value: mixed, source: string}> */
    public function all(?Hotel $hotel): array
    {
        $id = $hotel?->id ?? 0;
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }
        $planId = $hotel?->effectivePlan()?->id;
        /** @var Collection<int, FeatureSwitch> $rows */
        $rows = FeatureSwitch::active()
            ->where(fn ($q) => $q->where('level', 'everyone')
                ->when($planId, fn ($w) => $w->orWhere(fn ($p) => $p->where('level', 'plan')->where('target_id', $planId)))
                ->when($hotel, fn ($w) => $w->orWhere(fn ($h) => $h->where('level', 'hotel')->where('target_id', $hotel->id))))
            ->orderByDesc('id')->get();

        $out = [];
        foreach (self::registry() as $key => $def) {
            $out[$key] = ['value' => $def['default'], 'source' => 'default'];
            foreach (['hotel', 'plan', 'everyone'] as $level) {
                $row = $rows->first(fn (FeatureSwitch $r) => $r->key === $key && $r->level === $level);
                if ($row) {
                    $out[$key] = ['value' => $def['type'] === 'int' ? ($row->value === null ? null : (int) $row->value) : (bool) $row->value, 'source' => $level];
                    break;
                }
            }
        }

        return $this->memo[$id] = $out;
    }

    /** Forget what was read (after a change). */
    public function flush(): void
    {
        $this->memo = [];
    }
}
