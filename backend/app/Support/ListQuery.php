<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shared list behaviour (owner, 2026-11-07: every table is a data table): sort by a column,
 * 25 / 50 / 100 rows per page, and the same list as an Excel / CSV file.
 *
 *   ?sort=total&dir=desc   only columns the list allows (never raw input in SQL)
 *   ?per_page=50
 *   ?format=xlsx|csv       all rows with the current filters (at most EXPORT_LIMIT)
 */
class ListQuery
{
    public const PER_PAGE = [25, 50, 100];

    public const EXPORT_LIMIT = 5000;

    /**
     * @param  array<string, string|\Closure(Builder, string): mixed>  $allowed  sort key => column, or a closure(query, dir)
     */
    public static function sort(Builder $query, Request $request, array $allowed, string $defaultKey, string $defaultDir = 'desc'): Builder
    {
        $key = (string) $request->input('sort', $defaultKey);
        if (! array_key_exists($key, $allowed)) {
            $key = $defaultKey;
        }
        $dir = $request->input('dir') === 'asc' ? 'asc' : ($request->input('dir') === 'desc' ? 'desc' : $defaultDir);
        $by = $allowed[$key];
        $by instanceof \Closure ? $by($query, $dir) : $query->orderBy($by, $dir);

        return $query->orderBy($query->getModel()->getQualifiedKeyName(), $dir); // same order every time
    }

    public static function perPage(Request $request, int $default = 25): int
    {
        $n = (int) $request->input('per_page', $default);

        return in_array($n, self::PER_PAGE, true) ? $n : $default;
    }

    /** Is this a file request? */
    public static function wantsFile(Request $request): bool
    {
        return in_array($request->input('format'), ['xlsx', 'csv'], true);
    }

    /**
     * The list as a file. Only for people with the export permission (reports.export unless the list says otherwise).
     *
     * @param  list<string>  $headers
     * @param  \Closure(mixed): list<string|int|float|null>  $row
     */
    public static function file(Request $request, Builder $query, string $name, array $headers, \Closure $row, string $permission = 'reports.export'): Response
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'You cannot download this list.');
        $rows = $query->limit(self::EXPORT_LIMIT)->get()->map(fn ($r) => array_map(fn ($v) => $v === null ? '' : (string) $v, $row($r)))->all();
        $file = $name.'-'.now()->format('Y-m-d');

        return $request->input('format') === 'csv'
            ? response(Spreadsheet::csv($headers, $rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$file}.csv\""])
            : response(Spreadsheet::xlsx(mb_substr($name, 0, 31), $headers, $rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$file}.xlsx\""]);
    }
}
