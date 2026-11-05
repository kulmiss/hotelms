<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\ReportTarget;
use App\Models\User;
use App\Services\Reports\Insights;
use App\Support\HotelClock;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Daily report, forecast, targets, staff audit, my shift, my work, accounting export (reports.md). */
class InsightController extends Controller
{
    public function daily(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $date = $data['date'] ?? HotelClock::now($request->user()->hotel)->subDay()->toDateString();

        return response()->json(['data' => $this->insights($request)->daily($date) + ['currency' => $this->currency($request)]]);
    }

    public function forecast(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'between:7,180']]);

        return response()->json(['data' => $this->insights($request)->forecast((int) ($data['days'] ?? 90)) + ['currency' => $this->currency($request)]]);
    }

    public function targets(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? HotelClock::now($request->user()->hotel)->format('Y-m');

        return response()->json(['data' => ['month' => $month, 'rows' => $this->insights($request)->targets($month), 'currency' => $this->currency($request)]]);
    }

    public function saveTarget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'month' => ['required', 'date_format:Y-m'],
            'revenue_target' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'occupancy_target' => ['nullable', 'decimal:0,2', 'between:0,100'],
        ]);
        if (! $request->user()->accessibleBranches()->contains('id', (int) $data['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }
        $target = ReportTarget::updateOrCreate(['branch_id' => $data['branch_id'], 'month' => $data['month']], $data);

        return response()->json(['data' => $target]);
    }

    public function audit(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->insights($request)->audit($from, $to) + ['from' => $from, 'to' => $to, 'currency' => $this->currency($request)]]);
    }

    public function timeline(Request $request, string $user): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $hotel = $request->user()->hotel;
        $staff = User::where('hotel_id', $hotel->id)->findOrFail($user);
        $start = Carbon::parse($from, HotelClock::zone($hotel))->startOfDay()->utc();
        $end = Carbon::parse($to, HotelClock::zone($hotel))->endOfDay()->utc();

        return response()->json(['data' => ['user' => $staff->only(['id', 'name']), 'rows' => $this->insights($request)->timeline($staff->id, $start, $end)]]);
    }

    /** Always the signed-in person's own shift (never someone else's). */
    public function myShift(Request $request): JsonResponse
    {
        $data = $request->validate(['session' => ['nullable', 'integer']]);
        $user = $request->user();

        return response()->json(['data' => Insights::for($user->hotel, $user->accessibleBranches()->pluck('id')->all())->myShift($user, $data['session'] ?? null) + ['currency' => $this->currency($request)]]);
    }

    public function myWork(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'in:today,week,month']]);
        $user = $request->user();

        return response()->json(['data' => Insights::for($user->hotel, $user->accessibleBranches()->pluck('id')->all())->myWork($user, $data['period'] ?? 'today')]);
    }

    public function accounting(Request $request): JsonResponse|Response
    {
        [$from, $to] = $this->period($request);
        $lines = $this->insights($request)->journal($from, $to);
        $format = $request->input('format', 'json');
        if ($format === 'json') {
            return response()->json(['data' => ['from' => $from, 'to' => $to, 'lines' => $lines, 'currency' => $this->currency($request)]]);
        }
        $headers = ['Date', 'Account', 'Reference', 'Description', 'Debit', 'Credit'];
        $rows = array_map(fn ($l) => array_values($l), $lines);
        $name = "journal-{$from}-{$to}";

        return $format === 'csv'
            ? response(Spreadsheet::csv($headers, $rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""])
            : response(Spreadsheet::xlsx('Journal', $headers, $rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\""]);
    }

    // ------------------------------------------------------------ helpers

    private function insights(Request $request): Insights
    {
        $ids = $request->user()->accessibleBranches()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $b = $request->integer('branch_id');

        return Insights::for($request->user()->hotel, $b && in_array($b, $ids, true) ? [$b] : $ids);
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'format' => ['nullable', 'in:json,csv,xlsx']]);
        $today = HotelClock::now($request->user()->hotel)->toDateString();
        $from = $data['from'] ?? Carbon::parse($today)->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today;
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            throw ValidationException::withMessages(['from' => 'Choose at most 400 days.']);
        }

        return [$from, $to];
    }

    private function currency(Request $request): string
    {
        return $request->user()->hotel->default_currency ?? 'ETB';
    }
}
