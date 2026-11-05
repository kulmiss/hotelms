<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Services\Imports\RoomImporter;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Import from Excel / CSV (decisions.md W10):
 * download template → upload (parse) → match columns → check (preview, fix) → import → undo within 24 h.
 */
class ImportController extends Controller
{
    public function __construct(private RoomImporter $rooms) {}

    public function template(Request $request): Response
    {
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';
        $sample = RoomImporter::SAMPLE;
        if ($branch = $request->user()->accessibleBranches()->first()) {
            $sample = array_map(fn ($r) => array_replace($r, [0 => $branch->name]), $sample);
        }

        return $format === 'csv'
            ? response(Spreadsheet::csv(RoomImporter::COLUMNS, $sample), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="rooms-template.csv"',
            ])
            : response(Spreadsheet::xlsx('Rooms', RoomImporter::COLUMNS, $sample), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="rooms-template.xlsx"',
            ]);
    }

    /** Read the uploaded file; nothing is saved. */
    public function parse(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt']]);
        $file = $request->file('file');

        try {
            $rows = Spreadsheet::read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        return response()->json([
            'file_name' => $file->getClientOriginalName(),
            'headers' => array_shift($rows),
            'rows' => $rows,
            'columns' => RoomImporter::COLUMNS,
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rooms->check($this->rows($request), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file_name' => ['nullable', 'string', 'max:200']]);
        $import = $this->rooms->import($this->rows($request), $request->user(), $request->input('file_name'));

        return response()->json(['data' => $this->present($import)], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Import::with('user:id,name')->latest()->limit(30)->get()->map(fn (Import $i) => $this->present($i))]);
    }

    public function undo(Request $request, string $id): JsonResponse
    {
        $import = Import::findOrFail($id);
        $this->rooms->undo($import, $request->user());

        return response()->json(['data' => $this->present($import->fresh(['user']))]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(Request $request): array
    {
        $request->validate([
            'rows' => ['present', 'array', 'max:'.Spreadsheet::MAX_ROWS],
            'rows.*' => ['array'],
        ]);

        return array_values($request->input('rows', []));
    }

    /** @return array<string, mixed> */
    private function present(Import $i): array
    {
        return [
            'id' => $i->id,
            'type' => $i->type,
            'file_name' => $i->file_name,
            'rows_count' => $i->rows_count,
            'rooms' => count($i->created_ids['rooms'] ?? []),
            'room_types' => count($i->created_ids['room_types'] ?? []),
            'by' => $i->user?->name,
            'created_at' => $i->created_at->toIso8601String(),
            'undone_at' => $i->undone_at?->toIso8601String(),
            'can_undo' => $i->canUndo(),
        ];
    }
}
