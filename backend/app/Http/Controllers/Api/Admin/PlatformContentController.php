<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelInvoice;
use App\Models\PlatformSetting;
use App\Models\Release;
use App\Services\ActivityLogger;
use App\Services\HotelBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin: subscription invoices to hotels (area 3), releases and "What's new" (area 5),
 * and content – invoice details, welcome text and defaults for new hotels (area 10).
 */
class PlatformContentController extends Controller
{
    public function __construct(private ActivityLogger $log) {}

    // ------------------------------------------------------------------ invoices

    public function invoices(Hotel $hotel, HotelBilling $billing): JsonResponse
    {
        [$start, $end] = $billing->nextPeriod($hotel);

        return response()->json([
            'data' => HotelInvoice::where('hotel_id', $hotel->id)->with('plan:id,name')->latest('id')->get()->map->present()->values(),
            'next_period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
        ]);
    }

    public function issueInvoice(Request $request, Hotel $hotel, HotelBilling $billing): JsonResponse
    {
        $invoice = $billing->issue($hotel, $request->user());
        $this->log->log('hotel_invoice.issued', "Invoice {$invoice->number} for {$hotel->name}", $hotel);

        return response()->json(['data' => $invoice->load('plan:id,name')->present(), 'emailed' => $invoice->emailed_at !== null], 201);
    }

    public function sendInvoice(string $id, HotelBilling $billing): JsonResponse
    {
        $invoice = HotelInvoice::where('status', '!=', 'void')->findOrFail($id);
        if (! $billing->send($invoice)) {
            throw ValidationException::withMessages(['email' => 'Could not send: the hotel has no email address, or email is not set up.']);
        }

        return response()->json(['message' => 'Sent.']);
    }

    public function voidInvoice(string $id): JsonResponse
    {
        $invoice = HotelInvoice::where('status', 'unpaid')->findOrFail($id);
        $invoice->update(['status' => 'void']);
        $this->log->log('hotel_invoice.voided', "Invoice {$invoice->number} voided", $invoice->hotel);

        return response()->json(['message' => 'Voided.']);
    }

    // ------------------------------------------------------------------ releases

    public function releases(): JsonResponse
    {
        return response()->json(['data' => Release::latest('id')->get(), 'live_version' => config('app.version')]);
    }

    public function saveRelease(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $release = $id ? Release::findOrFail($id) : new Release(['status' => 'draft', 'created_by' => $request->user()->id]);
        $release->fill($data)->save();

        return response()->json(['data' => $release], $id ? 200 : 201);
    }

    /** Publishing shows the note to every hotel user once ("What's new"). */
    public function publishRelease(string $id): JsonResponse
    {
        $release = Release::findOrFail($id);
        $release->update(['status' => 'published', 'published_at' => $release->published_at ?? now()]);
        $this->log->log('release.published', "What's new published: {$release->version} – {$release->title}");

        return response()->json(['data' => $release]);
    }

    public function deleteRelease(string $id): JsonResponse
    {
        Release::where('status', 'draft')->findOrFail($id)->delete();

        return response()->json(['message' => 'Removed.']);
    }

    // ------------------------------------------------------------------ content

    private const CONTENT = ['billing_name', 'billing_tin', 'billing_address', 'payment_instructions', 'welcome_message', 'new_hotel_defaults'];

    public function content(): JsonResponse
    {
        return response()->json(['data' => collect(self::CONTENT)->mapWithKeys(fn ($k) => [$k => PlatformSetting::read($k)])]);
    }

    public function saveContent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'billing_name' => ['nullable', 'string', 'max:120'],
            'billing_tin' => ['nullable', 'string', 'max:30'],
            'billing_address' => ['nullable', 'string', 'max:200'],
            'payment_instructions' => ['nullable', 'string', 'max:1000'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'new_hotel_defaults' => ['required', 'array'],
            'new_hotel_defaults.deposit_value' => ['required', 'decimal:0,2', 'between:0,100'],
            'new_hotel_defaults.vat_rate' => ['required', 'decimal:0,2', 'between:0,100'],
            'new_hotel_defaults.invoice_footer' => ['nullable', 'string', 'max:300'],
            'new_hotel_defaults.receipt_paper' => ['required', Rule::in(['a4', 'receipt'])],
        ]);
        $data['new_hotel_defaults'] = array_intersect_key($data['new_hotel_defaults'], array_flip(['deposit_value', 'vat_rate', 'invoice_footer', 'receipt_paper']));
        foreach ($data as $key => $value) {
            PlatformSetting::write($key, $value);
        }
        $this->log->log('platform.content', 'Content changed: '.implode(', ', array_keys($data)));

        return $this->content();
    }
}
