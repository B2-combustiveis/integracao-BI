<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use App\Models\IntegrationService;

class AdminDashboardController extends Controller
{
    public function index(AdminOverviewService $overview): View
    {
        return view('admin.dashboard', ['overview' => $overview->get()]);
    }

    public function overview(AdminOverviewService $overview): JsonResponse
    {
        return response()->json($overview->get());
    }

    public function services(): View
    {
        $services = IntegrationService::query()->with(['runs' => fn ($query) => $query->latest()->limit(5)])
            ->orderBy('category')->orderBy('name')->get();
        return view('admin.services', compact('services'));
    }
}
