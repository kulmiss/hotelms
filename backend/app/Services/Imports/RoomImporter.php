<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Import;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import room types and rooms from the "Rooms" template (one row per room).
 * A new room type is created the first time its name appears for a branch,
 * using the prices on that row. Call inside the hotel's tenant context.
 */
class RoomImporter
{
    /** Template columns, in order. */
    public const COLUMNS = ['branch', 'room_type', 'room_number', 'floor', 'nightly_price', 'day_use_price', 'hourly_price', 'guests_included', 'max_guests', 'amenities', 'notes'];

    public const SAMPLE = [
        ['Main branch', 'Standard', '101', '1', 2500, 1500, 400, 2, 2, 'Wi-Fi, TV, Shower', ''],
        ['Main branch', 'Standard', '102', '1', '', '', '', '', '', '', ''],
        ['Main branch', 'Deluxe', '201', '2', 3800, 2200, '', 2, 3, 'Wi-Fi, TV, Balcony', 'Sea view'],
    ];

    /**
     * Check every row. Returns errors per row (1-based, as in the file after the header) and a summary.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{errors: array<int, array<string, string>>, general: list<string>, summary: array{rooms: int, new_types: list<string>}}
     */
    public function check(array $rows, User $user): array
    {
        return $this->plan($rows, $user)['report'];
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function import(array $rows, User $user, ?string $fileName): Import
    {
        $plan = $this->plan($rows, $user);
        $report = $plan['report'];
        if ($report['errors'] || $report['general']) {
            throw ValidationException::withMessages(['rows' => 'Fix the errors first.']);
        }

        return DB::transaction(function () use ($plan, $user, $fileName, $rows) {
            $typeIds = [];
            $created = ['room_types' => [], 'rooms' => []];
            foreach ($plan['types'] as $key => $t) {
                if ($t['id']) {
                    $typeIds[$key] = $t['id'];

                    continue;
                }
                $type = RoomType::create($t['data']);
                $typeIds[$key] = $type->id;
                $created['room_types'][] = $type->id;
            }
            foreach ($plan['rooms'] as $r) {
                $room = Room::create([
                    'branch_id' => $r['branch_id'], 'room_type_id' => $typeIds[$r['type_key']], 'number' => $r['number'],
                    'floor' => $r['floor'], 'notes' => $r['notes'], 'status' => 'available',
                ]);
                $created['rooms'][] = $room->id;
            }

            return Import::create([
                'user_id' => $user->id, 'type' => 'rooms', 'file_name' => $fileName,
                'rows_count' => count($rows), 'created_ids' => $created,
            ]);
        });
    }

    /** Remove what an import created (rooms first, then room types left without rooms). */
    public function undo(Import $import, User $user): void
    {
        if (! $import->canUndo()) {
            throw ValidationException::withMessages(['import' => 'This import can no longer be undone (24 hours have passed or it was already undone).']);
        }

        $rooms = $import->created_ids['rooms'] ?? [];
        if (ReservationRoom::whereIn('room_id', $rooms)->whereIn('status', Reservation::ACTIVE)->exists()
            || ReservationRoom::whereIn('room_type_id', $import->created_ids['room_types'] ?? [])->whereIn('status', Reservation::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['import' => 'Some imported rooms already have guests or bookings, so this import cannot be undone. Change those bookings first.']);
        }

        DB::transaction(function () use ($import, $user, $rooms) {
            Room::whereIn('id', $rooms)->get()->each->delete();
            RoomType::whereIn('id', $import->created_ids['room_types'] ?? [])->whereDoesntHave('rooms')->get()->each->delete();
            $import->update(['undone_at' => now(), 'undone_by' => $user->id]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{report: array{errors: array<int, array<string, string>>, general: list<string>, summary: array{rooms: int, new_types: list<string>}}, types: array<string, array{id: ?int, data: array<string, mixed>}>, rooms: list<array{branch_id: int, type_key: string, number: string, floor: ?string, notes: ?string}>}
     */
    private function plan(array $rows, User $user): array
    {
        $branches = $user->accessibleBranches();
        $existingTypes = RoomType::whereIn('branch_id', $branches->pluck('id'))->get()
            ->keyBy(fn (RoomType $t) => $t->branch_id.'|'.mb_strtolower($t->name));
        $existingRooms = Room::whereIn('branch_id', $branches->pluck('id'))->get(['branch_id', 'number'])
            ->map(fn (Room $r) => $r->branch_id.'|'.mb_strtolower($r->number))->flip();
        $currency = $user->hotel->default_currency ?? 'ETB';

        $errors = [];
        $types = [];
        $rooms = [];
        $seen = [];
        $newTypeNames = [];

        foreach (array_values($rows) as $i => $raw) {
            $line = $i + 1;
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : ''), array_intersect_key($raw, array_flip(self::COLUMNS)));
            $err = [];

            $branch = $this->branch($branches, $row['branch'] ?? '');
            if (! $branch) {
                $err['branch'] = ($row['branch'] ?? '') === '' ? 'Write the branch name.' : 'Unknown branch "'.$row['branch'].'".';
            }
            $typeName = $row['room_type'] ?? '';
            if ($typeName === '') {
                $err['room_type'] = 'Write the room type.';
            } elseif (mb_strlen($typeName) > 60) {
                $err['room_type'] = 'At most 60 characters.';
            }

            $prices = [];
            foreach (['nightly_price', 'day_use_price', 'hourly_price'] as $f) {
                $v = $this->number($row[$f] ?? '');
                if ($v === false) {
                    $err[$f] = 'Must be a number.';
                }
                $prices[$f] = $v ?: null;
            }
            $guests = [];
            foreach (['guests_included', 'max_guests'] as $f) {
                $v = $row[$f] ?? '';
                if ($v !== '' && (! ctype_digit($v) || (int) $v < 1 || (int) $v > 20)) {
                    $err[$f] = 'A whole number from 1 to 20.';
                }
                $guests[$f] = $v === '' ? null : (int) $v;
            }

            if ($branch && $typeName !== '') {
                $key = $branch->id.'|'.mb_strtolower($typeName);
                if (! isset($types[$key])) {
                    if ($existing = $existingTypes->get($key)) {
                        $types[$key] = ['id' => $existing->id, 'data' => []];
                    } elseif ($prices['nightly_price'] === null) {
                        $err['nightly_price'] = 'New room type "'.$typeName.'": write its price per night on its first row.';
                    } else {
                        $base = $guests['guests_included'] ?? 2;
                        $max = max($base, $guests['max_guests'] ?? $base);
                        $types[$key] = ['id' => null, 'data' => [
                            'branch_id' => $branch->id, 'name' => $typeName, 'currency' => $currency,
                            'nightly_price' => $prices['nightly_price'], 'day_use_price' => $prices['day_use_price'], 'hourly_price' => $prices['hourly_price'],
                            'base_capacity' => $base, 'max_capacity' => $max,
                            'amenities' => array_values(array_filter(array_map('trim', explode(',', $row['amenities'] ?? '')))),
                        ]];
                        $newTypeNames[] = $typeName.' ('.$branch->name.')';
                    }
                }

                $number = $row['room_number'] ?? '';
                if ($number !== '') {
                    $roomKey = $branch->id.'|'.mb_strtolower($number);
                    if (mb_strlen($number) > 20) {
                        $err['room_number'] = 'At most 20 characters.';
                    } elseif ($existingRooms->has($roomKey)) {
                        $err['room_number'] = 'Room '.$number.' already exists in '.$branch->name.'.';
                    } elseif (isset($seen[$roomKey])) {
                        $err['room_number'] = 'Room '.$number.' is also on row '.$seen[$roomKey].'.';
                    } else {
                        $seen[$roomKey] = $line;
                        if (isset($types[$key])) {
                            $rooms[] = [
                                'branch_id' => $branch->id, 'type_key' => $key, 'number' => $number,
                                'floor' => ($row['floor'] ?? '') !== '' ? mb_substr($row['floor'], 0, 10) : null,
                                'notes' => ($row['notes'] ?? '') !== '' ? mb_substr($row['notes'], 0, 500) : null,
                            ];
                        }
                    }
                }
            }

            if ($err) {
                $errors[$line] = $err;
            }
        }

        $general = [];
        if (! $rows) {
            $general[] = 'The file has no rows.';
        }
        $max = $user->hotel->effectivePlan()?->max_rooms;
        $used = Room::count();
        if ($max !== null && $used + count($rooms) > $max) {
            $general[] = "Your plan allows {$max} rooms ({$used} used); this file adds ".count($rooms).'. Upgrade the plan or import fewer rooms.';
        }

        return [
            'report' => ['errors' => $errors, 'general' => $general, 'summary' => ['rooms' => count($rooms), 'new_types' => $newTypeNames]],
            'types' => $types,
            'rooms' => $rooms,
        ];
    }

    /** @param  Collection<int, Branch>  $branches */
    private function branch(Collection $branches, string $name): ?Branch
    {
        if ($name === '') {
            return $branches->count() === 1 ? $branches->first() : null;
        }
        $n = mb_strtolower($name);

        return $branches->first(fn (Branch $b) => mb_strtolower($b->name) === $n || ($b->code && mb_strtolower($b->code) === $n));
    }

    /** "2,500.00" → "2500.00"; '' → null; not a number → false. */
    private function number(string $v): string|false|null
    {
        $v = str_replace([',', ' ', 'ETB', 'birr', 'Birr'], '', $v);
        if ($v === '') {
            return null;
        }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > 99999999) {
            return false;
        }

        return number_format((float) $v, 2, '.', ''); // the file's own number; rounded to cents
    }
}
