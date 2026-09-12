<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AuditLogController extends Controller
{
    public function index(): JsonResponse
    {
        $logs = AuditLog::latest()->paginate(30);
        return response()->json(['status' => 'success', 'data' => $logs], Response::HTTP_OK);
    }
}
