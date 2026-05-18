<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Shared meta endpoint for the My Reports module — surfaces the filter
 * dropdowns (POE, district, province, year list, gender) constrained by
 * the caller's privilege scope. Used by every report's filter wizard.
 */
final class ReportsMetaController extends Controller
{
    public function __construct(
        protected ReportScope $scope,
        protected ReportAccess $access,
    ) {
    }

    public function meta(Request $request): JsonResponse
    {
        $scope = $this->scope->descriptor($request);

        $years = [];
        $current = (int) Carbon::now()->year;
        for ($y = $current; $y >= $current - 5; $y--) {
            $years[] = $y;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'poes'      => $this->scope->allowedPoes($scope),
                'districts' => $this->scope->allowedDistricts($scope),
                'provinces' => $this->scope->allowedProvinces($scope),
                'years'     => $years,
                'quarters'  => [
                    1 => 'Q1 (Jan–Mar)',
                    2 => 'Q2 (Apr–Jun)',
                    3 => 'Q3 (Jul–Sep)',
                    4 => 'Q4 (Oct–Dec)',
                ],
                'months' => [
                    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
                ],
                'genders'   => ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other', 'UNKNOWN' => 'Unknown'],
                'reports'   => $this->access->visibleKeys($scope),
            ],
            'meta' => [
                'scope_label' => $scope['label'] ?? '—',
                'scope_level' => $scope['scope_level'] ?? 'SELF',
                'is_super'    => (bool) ($scope['is_super'] ?? false),
            ],
        ]);
    }
}
