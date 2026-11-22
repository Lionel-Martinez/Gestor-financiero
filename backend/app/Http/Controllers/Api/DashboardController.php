<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function show(Request $r): JsonResponse
    {
        $data = $r->validate([
            'context' => 'required|in:general,PER,LMS',
            'from' => 'required|date', 'to' => 'required|date|after_or_equal:from',
            'reference_currency' => 'nullable|string|max:10', 'rate_type' => 'nullable|string|max:20',
        ]);
        $user = $r->user();

        $spaces = DB::table('spaces')->where('user_id', $user->id)
            ->when($data['context'] !== 'general', fn ($q) => $q->where('code', $data['context']))
            ->pluck('id')->all();
        abort_if($spaces === [], 404, 'Espacio inexistente.');

        return response()->json($this->dashboard->summary(
            $user->id, $spaces,
            CarbonImmutable::parse($data['from'], 'UTC')->startOfDay(), CarbonImmutable::parse($data['to'], 'UTC')->startOfDay(),
            $data['reference_currency'] ?? $user->reference_currency, $data['rate_type'] ?? $user->reference_rate_type,
        ));
    }
}
