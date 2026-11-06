<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payment proof (optional): a photo from the camera or a file from the device (image or PDF).
 * Upload first (returns an id), then send that id with the payment – or attach it to a payment later.
 */
class PaymentProofController extends Controller
{
    public const MAX_KB = 8192;

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('payments.take'), 403);
        $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf']]);
        $file = $request->file('file');
        $hotel = $request->user()->hotel;
        $ext = match ($file->getMimeType()) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic', 'image/heif' => 'heic', default => 'jpg',
        };
        $path = $file->storeAs("payment-proofs/{$hotel->id}", Str::uuid().'.'.$ext, 'local');
        $proof = PaymentProof::create([
            'path' => $path, 'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize(), 'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $proof->id, 'mime' => $proof->mime]], 201);
    }

    /** The file itself, for staff who can see payments of this hotel (tenant scope keeps other hotels out). */
    public function show(Request $request, string $id): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('invoices.view') || $user->hasPermission('payments.view') || $user->hasPermission('reservations.view'), 403);
        $proof = PaymentProof::findOrFail($id);
        abort_unless($proof->payment_id || $proof->uploaded_by === $user->id, 404);
        abort_unless(Storage::disk('local')->exists($proof->path), 404);

        return Storage::disk('local')->response($proof->path, 'payment-proof-'.$proof->id.'.'.pathinfo($proof->path, PATHINFO_EXTENSION), [
            'Content-Type' => $proof->mime, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Add (or replace) the proof of a payment already recorded. */
    public function attach(Request $request, string $payment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('payments.take'), 403);
        $data = $request->validate(['proof_id' => ['required', 'integer']]);
        $p = Payment::findOrFail($payment);
        if (! self::link((int) $data['proof_id'], $p, $request->user()->id)) {
            throw ValidationException::withMessages(['proof_id' => 'Upload the photo again.']);
        }

        return response()->json(['data' => ['payment_id' => $p->id, 'proof_id' => (int) $data['proof_id']]]);
    }

    /** Link an uploaded, not yet used proof (of this person) to a payment. */
    public static function link(?int $proofId, Payment $payment, int $userId): bool
    {
        if (! $proofId) {
            return false;
        }

        return PaymentProof::whereKey($proofId)->whereNull('payment_id')->where('uploaded_by', $userId)->update(['payment_id' => $payment->id]) === 1;
    }
}
