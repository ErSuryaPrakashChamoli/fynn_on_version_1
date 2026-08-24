<?php

namespace App\Services\Performance;

use App\Models\PerformanceMetricRatio;

/**
 * Computes one admin-defined ratio's value from a raw-metrics array (as
 * returned by EmployeePerformanceMetricsService::rawMetrics()).
 */
class RatioCalculator
{
    /**
     * Null means the denominator was zero/missing — render as "—", not 0%.
     */
    public function compute(array $rawMetrics, PerformanceMetricRatio $ratio): ?float
    {
        $numerator = (float) ($rawMetrics[$ratio->numerator_key] ?? 0);
        $denominator = (float) ($rawMetrics[$ratio->denominator_key] ?? 0);

        if ($denominator <= 0) {
            return null;
        }

        $value = $numerator / $denominator;

        return $ratio->format === PerformanceMetricRatio::FORMAT_PERCENTAGE
            ? round($value * 100, 1)
            : round($value, 2);
    }

    public function formatValue(?float $value, PerformanceMetricRatio $ratio): string
    {
        if ($value === null) {
            return '—';
        }

        return $ratio->format === PerformanceMetricRatio::FORMAT_PERCENTAGE
            ? "{$value}%"
            : (string) $value;
    }
}
