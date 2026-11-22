<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SyncController extends Controller
{
    public function __construct(private readonly SyncService $sync) {}

    public function push(Request $r): JsonResponse
    {
        $data = $r->validate(['operations' => 'required|array|max:100', 'operations.*' => 'array']);

        return response()->json([
            'results' => $this->sync->push($r->user()->id, $r->attributes->get('device_id'), $data['operations']),
        ]);
    }

    public function pull(Request $r): JsonResponse
    {
        $data = $r->validate(['since' => 'nullable|date']);

        return response()->json($this->sync->pull($r->user()->id, $data['since'] ?? null));
    }
}
