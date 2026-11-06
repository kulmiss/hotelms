<?php

namespace App\Services\Modules;

use App\Models\CashierSession;
use App\Models\Hotel;
use App\Models\HotelCurrency;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\HotelModules;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cashier shift close (workflows.md W10): starting cash per currency, cash taken is
 * linked to the open shift, count at the end, difference explained, manager approves.
 */
class Cashier
{
    public function __construct(private HotelModules $modules) {}

    public function isOn(Hotel $hotel): bool
    {
        return $this->modules->isEnabled($hotel, 'cashier_close');
    }

    public function current(User $user): ?CashierSession
    {
        return CashierSession::where('user_id', $user->id)->where('status', 'open')->latest('opened_at')->first();
    }

    /**
     * The shift a cash payment belongs to; blocked when the module is on and no shift is open.
     * Returns null for non-cash payments or when the module is off.
     */
    public function sessionForPayment(User $user, PaymentMethod $method, int $branchId): ?int
    {
        if ($method->type !== 'cash' || ! $this->isOn($user->hotel)) {
            return null;
        }
        $session = $this->current($user);
        if (! $session) {
            throw ValidationException::withMessages(['payment_method_id' => 'Open your cashier shift before taking cash.']);
        }
        if ($session->branch_id !== $branchId) {
            throw ValidationException::withMessages(['payment_method_id' => 'Your open cashier shift is in another branch.']);
        }

        return $session->id;
    }

    /** @param  array<string, string>  $opening  currency => amount */
    public function open(User $user, int $branchId, array $opening): CashierSession
    {
        $main = $user->hotel->default_currency ?? 'ETB';
        $opening = array_change_key_case($opening, CASE_UPPER) + [$main => '0'];
        $known = HotelCurrency::pluck('code')->map(fn ($c) => strtoupper($c))->push($main)->all();
        foreach (array_keys($opening) as $currency) {
            if (! in_array($currency, $known, true)) {
                throw ValidationException::withMessages(["opening.$currency" => "{$currency} is not one of your currencies."]);
            }
        }

        return DB::transaction(function () use ($user, $branchId, $opening) {
            // One open shift per person, also on a double tap.
            User::whereKey($user->id)->lockForUpdate()->first();
            if ($this->current($user)) {
                throw ValidationException::withMessages(['shift' => 'You already have an open cashier shift.']);
            }
            $session = CashierSession::create(['branch_id' => $branchId, 'user_id' => $user->id, 'opened_at' => now(), 'status' => 'open']);
            foreach ($opening as $currency => $amount) {
                $session->balances()->create(['currency' => strtoupper($currency), 'opening_amount' => Money::round((string) $amount)]);
            }

            return $session->fresh('balances');
        });
    }

    /**
     * Cash that should be in the drawer, per currency: opening + cash taken − cash refunded.
     *
     * @return array<string, array{opening: string, cash_in: string, cash_out: string, expected: string}>
     */
    public function expected(CashierSession $session): array
    {
        $rows = [];
        foreach ($session->balances as $b) {
            $rows[$b->currency] = ['opening' => (string) $b->opening_amount, 'cash_in' => '0.00', 'cash_out' => '0.00', 'expected' => (string) $b->opening_amount];
        }
        foreach (Payment::where('cashier_session_id', $session->id)->get(['type', 'amount', 'currency']) as $p) {
            $rows[$p->currency] ??= ['opening' => '0.00', 'cash_in' => '0.00', 'cash_out' => '0.00', 'expected' => '0.00'];
            $key = $p->type === 'refund' ? 'cash_out' : 'cash_in';
            $rows[$p->currency][$key] = Money::add($rows[$p->currency][$key], (string) $p->amount);
        }
        foreach ($rows as $c => $r) {
            $rows[$c]['expected'] = Money::sub(Money::add($r['opening'], $r['cash_in']), $r['cash_out']);
        }

        return $rows;
    }

    /** @param  array<string, string>  $counted  currency => amount */
    public function close(CashierSession $session, array $counted, ?string $note): CashierSession
    {
        if ($session->status !== 'open') {
            throw ValidationException::withMessages(['shift' => 'This shift is already closed.']);
        }
        $expected = $this->expected($session);
        $differs = false;
        foreach ($expected as $currency => $row) {
            if (! array_key_exists($currency, $counted)) {
                throw ValidationException::withMessages(["counted.$currency" => "Count the {$currency} cash."]);
            }
            if (Money::compare(Money::round((string) $counted[$currency]), $row['expected']) !== 0) {
                $differs = true;
            }
        }
        if ($differs && trim((string) $note) === '') {
            throw ValidationException::withMessages(['notes' => 'The count is different from what is expected. Explain why.']);
        }

        return DB::transaction(function () use ($session, $counted, $note, $expected) {
            if (CashierSession::whereKey($session->id)->lockForUpdate()->value('status') !== 'open') {
                throw ValidationException::withMessages(['shift' => 'This shift is already closed.']);
            }
            foreach ($expected as $currency => $row) {
                $count = Money::round((string) $counted[$currency]);
                $session->balances()->updateOrCreate(['currency' => $currency], [
                    'opening_amount' => $row['opening'], 'expected_amount' => $row['expected'],
                    'counted_amount' => $count, 'difference' => Money::sub($count, $row['expected']),
                ]);
            }
            $session->update(['status' => 'closed', 'closed_at' => now(), 'notes' => $note]);

            return $session->fresh('balances');
        });
    }

    public function approve(CashierSession $session, User $manager): CashierSession
    {
        if ($session->status !== 'closed') {
            throw ValidationException::withMessages(['shift' => 'Only closed shifts can be approved.']);
        }
        if ($session->user_id === $manager->id && ! $manager->isHotelAdmin()) {
            throw ValidationException::withMessages(['shift' => 'Another manager must approve your own shift.']);
        }

        return DB::transaction(function () use ($session, $manager) {
            if (CashierSession::whereKey($session->id)->lockForUpdate()->value('status') !== 'closed') {
                throw ValidationException::withMessages(['shift' => 'Only closed shifts can be approved.']);
            }
            $session->update(['status' => 'approved', 'approved_by' => $manager->id, 'approved_at' => now()]);

            return $session->fresh('balances');
        });
    }
}
