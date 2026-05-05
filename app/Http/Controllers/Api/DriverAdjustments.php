<?php

namespace App\Http\Controllers\Api;

use App\Models\Driver;
use App\Models\DriverAdjustment;
use App\Models\DriverAdjustmentAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DriverAdjustments
{
    private const TYPE_BONUS = 'bonus';

    private const TYPE_PENALTY = 'penalty';

    public function list(Request $request): array
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'adjustment_type' => ['nullable', 'in:bonus,penalty'],
            'status' => ['nullable', 'in:open,closed'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);
        $query = DriverAdjustment::query()->with('driver');
        $this->applyListFilters($query, $validated);

        if (! empty($validated['cursor'])) {
            [$cursorDate, $cursorId] = $this->decodeCursor($validated['cursor']);
            $query->where(function ($q) use ($cursorDate, $cursorId) {
                $q->whereDate('event_date', '<', $cursorDate)
                    ->orWhere(function ($q2) use ($cursorDate, $cursorId) {
                        $q2->whereDate('event_date', '=', $cursorDate)->where('id', '<', $cursorId);
                    });
            });
        }

        $items = $query
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $items->count() > $limit;
        if ($hasMore) {
            $items = $items->slice(0, $limit)->values();
        }

        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last = $items->last();
            $nextCursor = $this->encodeCursor($last->event_date instanceof Carbon ? $last->event_date->toDateString() : (string) $last->event_date, (int) $last->id);
        }

        return [
            'items' => $items->map(fn (DriverAdjustment $item) => $this->toListResource($item))->toArray(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    public function summary(Request $request): array
    {
        $validated = $request->validate([
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'adjustment_type' => ['nullable', 'in:bonus,penalty'],
            'status' => ['nullable', 'in:open,closed'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = DriverAdjustment::query();
        $this->applyListFilters($query, $validated);

        $stats = (clone $query)
            ->selectRaw(
                'COUNT(*) as total_count, '.
                'SUM(CASE WHEN adjustment_type = ? THEN total_amount ELSE 0 END) as bonus_total, '.
                'SUM(CASE WHEN adjustment_type = ? THEN total_amount ELSE 0 END) as penalty_total',
                [self::TYPE_BONUS, self::TYPE_PENALTY]
            )
            ->first();

        $penaltyOpen = (clone $query)
            ->where('adjustment_type', self::TYPE_PENALTY)
            ->where('status', 'open')
            ->sum('total_amount');

        return [
            'total_count' => (int) ($stats->total_count ?? 0),
            'bonus_total' => (float) ($stats->bonus_total ?? 0),
            'penalty_total' => (float) ($stats->penalty_total ?? 0),
            'penalty_open_total' => (float) $penaltyOpen,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $id = (int) $request->input('id');
        $adjustment = DriverAdjustment::with(['driver', 'parts', 'attachments'])->find($id);
        if (! $adjustment) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        return response()->json($this->toDetailResource($adjustment));
    }

    public function store(Request $request): JsonResponse
    {
        [$validated, $parts] = $this->validatePayload($request, false);

        $adjustment = DB::transaction(function () use ($validated, $parts, $request) {
            $adjustment = DriverAdjustment::create([
                'driver_id' => (int) $validated['driver_id'],
                'adjustment_type' => $validated['adjustment_type'],
                'event_date' => Carbon::parse($validated['event_date'])->toDateString(),
                'total_amount' => (float) $validated['total_amount'],
                'comment' => trim((string) $validated['comment']),
                'status' => $validated['adjustment_type'] === self::TYPE_PENALTY ? 'open' : 'closed',
            ]);

            $this->syncParts($adjustment, $parts);
            $this->storeAttachments($adjustment, $request);
            $adjustment->attachments_count = $adjustment->attachments()->count();
            $adjustment->save();

            return $adjustment;
        });

        return response()->json($this->toDetailResource($adjustment->fresh(['driver', 'parts', 'attachments'])), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $adjustment = DriverAdjustment::find((int) $request->input('id'));
        if (! $adjustment) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        [$validated, $parts] = $this->validatePayload($request, true);

        DB::transaction(function () use ($adjustment, $validated, $parts, $request) {
            $adjustment->update([
                'driver_id' => (int) $validated['driver_id'],
                'adjustment_type' => $validated['adjustment_type'],
                'event_date' => Carbon::parse($validated['event_date'])->toDateString(),
                'total_amount' => (float) $validated['total_amount'],
                'comment' => trim((string) $validated['comment']),
                'status' => $this->resolveStatus((string) $validated['adjustment_type'], $parts),
            ]);

            $this->syncParts($adjustment, $parts);
            $this->storeAttachments($adjustment, $request);
            $adjustment->attachments_count = $adjustment->attachments()->count();
            $adjustment->save();
        });

        return response()->json($this->toDetailResource($adjustment->fresh(['driver', 'parts', 'attachments'])));
    }

    public function deleteAttachment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:driver_adjustment_attachments,id'],
        ]);

        $attachment = DriverAdjustmentAttachment::find((int) $validated['id']);
        if (! $attachment) {
            return response()->json(['message' => 'Вложение не найдено'], 404);
        }

        $adjustment = $attachment->adjustment;
        if ($attachment->path) {
            Storage::disk($attachment->disk ?: 'public')->delete($attachment->path);
        }
        $attachment->delete();

        if ($adjustment) {
            $adjustment->attachments_count = $adjustment->attachments()->count();
            $adjustment->save();
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $adjustment = DriverAdjustment::with('attachments')->find((int) $request->input('id'));
        if (! $adjustment) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        DB::transaction(function () use ($adjustment) {
            foreach ($adjustment->attachments as $attachment) {
                if ($attachment->path) {
                    Storage::disk($attachment->disk ?: 'public')->delete($attachment->path);
                }
            }
            $adjustment->delete();
        });

        return response()->json(['success' => true]);
    }

    private function validatePayload(Request $request, bool $isUpdate): array
    {
        $rules = [
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'adjustment_type' => ['required', 'in:bonus,penalty'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'comment' => ['required', 'string', 'max:5000'],
            'parts' => ['nullable'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'image', 'max:5120'],
        ];
        if ($isUpdate) {
            $rules['id'] = ['required', 'integer', 'exists:driver_adjustments,id'];
        }
        $validated = $request->validate($rules);

        $parts = $this->normalizeParts($validated['parts'] ?? null);
        $this->assertBusinessRules($validated, $parts);

        return [$validated, $parts];
    }

    private function normalizeParts(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            }
        }

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'parts' => 'Некорректный формат частей штрафа.',
            ]);
        }

        $parts = [];
        foreach (array_values($raw) as $idx => $part) {
            if (! is_array($part)) {
                throw ValidationException::withMessages([
                    'parts' => 'Некорректный формат части штрафа.',
                ]);
            }

            $amount = isset($part['amount']) ? (float) $part['amount'] : 0.0;
            $dueDate = isset($part['due_date']) ? (string) $part['due_date'] : '';
            $comment = isset($part['comment']) ? trim((string) $part['comment']) : null;
            $isApplied = ! empty($part['is_applied']);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "parts.{$idx}.amount" => 'Сумма части должна быть больше 0.',
                ]);
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                throw ValidationException::withMessages([
                    "parts.{$idx}.due_date" => 'Укажите дату части в формате ГГГГ-ММ-ДД.',
                ]);
            }

            $parts[] = [
                'part_no' => $idx + 1,
                'amount' => round($amount, 2),
                'due_date' => Carbon::parse($dueDate)->toDateString(),
                'comment' => $comment === '' ? null : $comment,
                'is_applied' => $isApplied,
                'applied_at' => $isApplied ? now() : null,
            ];
        }

        return $parts;
    }

    private function assertBusinessRules(array $validated, array $parts): void
    {
        $type = (string) $validated['adjustment_type'];
        $total = round((float) $validated['total_amount'], 2);
        $comment = trim((string) ($validated['comment'] ?? ''));

        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Комментарий обязателен.',
            ]);
        }

        if ($type === self::TYPE_BONUS) {
            if ($parts !== []) {
                throw ValidationException::withMessages([
                    'parts' => 'Для надбавки части не используются.',
                ]);
            }

            return;
        }

        if ($parts === []) {
            throw ValidationException::withMessages([
                'parts' => 'Для штрафа требуется минимум одна часть.',
            ]);
        }

        $partsSum = round(array_sum(array_column($parts, 'amount')), 2);
        if (abs($partsSum - $total) > 0.01) {
            throw ValidationException::withMessages([
                'parts' => 'Сумма частей должна быть равна общей сумме штрафа.',
            ]);
        }
    }

    private function syncParts(DriverAdjustment $adjustment, array $parts): void
    {
        $adjustment->parts()->delete();
        if ($adjustment->adjustment_type === self::TYPE_PENALTY && $parts !== []) {
            $adjustment->parts()->createMany($parts);
        }
    }

    private function storeAttachments(DriverAdjustment $adjustment, Request $request): void
    {
        $files = $request->file('attachments', []);
        if (! is_array($files) || $files === []) {
            return;
        }

        foreach ($files as $file) {
            if (! $file) {
                continue;
            }
            $storedPath = $file->store("driver-adjustments/{$adjustment->id}", 'public');
            $adjustment->attachments()->create([
                'disk' => 'public',
                'path' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    private function applyListFilters($query, array $validated): void
    {
        if (! empty($validated['driver_id'])) {
            $query->where('driver_id', (int) $validated['driver_id']);
        }
        if (! empty($validated['adjustment_type'])) {
            $query->where('adjustment_type', (string) $validated['adjustment_type']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('event_date', '>=', (string) $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('event_date', '<=', (string) $validated['date_to']);
        }
    }

    private function resolveStatus(string $type, array $parts): string
    {
        if ($type === self::TYPE_BONUS) {
            return 'closed';
        }

        foreach ($parts as $part) {
            if (empty($part['is_applied'])) {
                return 'open';
            }
        }

        return 'closed';
    }

    private function toListResource(DriverAdjustment $item): array
    {
        $driver = $item->driver ?? Driver::find($item->driver_id);

        return [
            'id' => $item->id,
            'driver_id' => $item->driver_id,
            'driver_name' => $driver?->full_name,
            'adjustment_type' => $item->adjustment_type,
            'event_date' => $item->event_date instanceof Carbon ? $item->event_date->toDateString() : (string) $item->event_date,
            'total_amount' => $item->total_amount,
            'status' => $item->status,
            'comment' => $item->comment,
            'attachments_count' => (int) $item->attachments_count,
        ];
    }

    private function toDetailResource(DriverAdjustment $item): array
    {
        $base = $this->toListResource($item);
        $parts = $item->parts()->orderBy('part_no')->get();
        $attachments = $item->attachments()->orderByDesc('id')->get();

        $base['parts'] = $parts->map(function ($part) {
            return [
                'id' => $part->id,
                'part_no' => $part->part_no,
                'amount' => $part->amount,
                'due_date' => $part->due_date instanceof Carbon ? $part->due_date->toDateString() : (string) $part->due_date,
                'is_applied' => (bool) $part->is_applied,
                'applied_at' => $part->applied_at?->toDateTimeString(),
                'comment' => $part->comment,
            ];
        })->toArray();

        $base['attachments'] = $attachments->map(function (DriverAdjustmentAttachment $attachment) {
            return [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime' => $attachment->mime,
                'size' => (int) $attachment->size,
                'url' => $attachment->publicUrl(),
            ];
        })->toArray();

        return $base;
    }

    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            throw ValidationException::withMessages([
                'cursor' => 'Некорректный курсор.',
            ]);
        }
        [$date, $id] = explode('|', $decoded, 2);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! ctype_digit($id)) {
            throw ValidationException::withMessages([
                'cursor' => 'Некорректный курсор.',
            ]);
        }

        return [$date, (int) $id];
    }

    private function encodeCursor(string $date, int $id): string
    {
        return base64_encode($date.'|'.$id);
    }
}
