<?php

namespace App\Services\Demo;

use App\Models\Demo\DemoApplication;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoEmployee;
use App\Models\Demo\DemoLead;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every number the demo dashboard shows, computed from the sandbox
 * tables.
 *
 * Nothing here can touch production: the only models it names are
 * demo_* ones. Results are memoized per tenant for the request because
 * several widgets ask for the same headline figures.
 */
class DemoMetricsService
{
    /** @var array<int, array<string, mixed>> */
    protected array $cache = [];

    /**
     * @return array{
     *     total_leads: int, new_leads: int, qualified_leads: int,
     *     applications: int, sanctioned: int, disbursed: int,
     *     disbursed_amount: int, sanctioned_amount: int,
     *     conversion_rate: float, customers: int, employees: int
     * }
     */
    public function headline(Tenant $tenant): array
    {
        return $this->cache[$tenant->getKey()] ??= $this->computeHeadline($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeHeadline(Tenant $tenant): array
    {
        $tenantId = $tenant->getKey();

        $leads = DemoLead::where('tenant_id', $tenantId);
        $totalLeads = (clone $leads)->count();

        $applications = DemoApplication::where('tenant_id', $tenantId);
        $totalApplications = (clone $applications)->count();

        $sanctioned = (clone $applications)->whereIn('status', ['sanctioned', 'disbursed'])->count();
        $disbursed = (clone $applications)->where('status', 'disbursed')->count();

        return [
            'total_leads' => $totalLeads,
            'new_leads' => (clone $leads)->where('status', 'new')->count(),
            'qualified_leads' => (clone $leads)->whereIn('status', ['qualified', 'converted'])->count(),
            'applications' => $totalApplications,
            'sanctioned' => $sanctioned,
            'disbursed' => $disbursed,
            'sanctioned_amount' => (int) (clone $applications)->sum('sanctioned_amount'),
            'disbursed_amount' => (int) (clone $applications)->sum('disbursed_amount'),
            'conversion_rate' => $totalLeads > 0 ? round(($disbursed / $totalLeads) * 100, 1) : 0.0,
            'customers' => DemoCustomer::where('tenant_id', $tenantId)->count(),
            'employees' => DemoEmployee::where('tenant_id', $tenantId)->where('is_active', true)->count(),
        ];
    }

    /**
     * Leads created per month for the last $months months, oldest first.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function leadTrend(Tenant $tenant, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = DemoLead::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn ($lead) => $lead->created_at->format('Y-m'))
            ->map->count();

        return $this->fillMonths($start, $months, $rows);
    }

    /**
     * Disbursed amount per month, in lakhs, for the chart's y-axis.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function disbursalTrend(Tenant $tenant, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = DemoApplication::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', 'disbursed')
            ->whereNotNull('disbursed_on')
            ->where('disbursed_on', '>=', $start)
            ->get(['disbursed_on', 'disbursed_amount'])
            ->groupBy(fn ($row) => $row->disbursed_on->format('Y-m'))
            ->map(fn (Collection $group) => round($group->sum('disbursed_amount') / 100000, 2));

        return $this->fillMonths($start, $months, $rows);
    }

    /**
     * The lead -> disbursal funnel, in order.
     *
     * @return array<string, int>
     */
    public function funnel(Tenant $tenant): array
    {
        $metrics = $this->headline($tenant);

        return [
            'Leads' => $metrics['total_leads'],
            'Qualified' => $metrics['qualified_leads'],
            'Applications' => $metrics['applications'],
            'Sanctioned' => $metrics['sanctioned'],
            'Disbursed' => $metrics['disbursed'],
        ];
    }

    /**
     * Applications grouped by loan product name.
     *
     * @return array<string, int>
     */
    public function productDistribution(Tenant $tenant): array
    {
        return DemoApplication::query()
            ->where('demo_applications.tenant_id', $tenant->getKey())
            ->join('demo_loan_products', 'demo_loan_products.id', '=', 'demo_applications.demo_loan_product_id')
            ->selectRaw('demo_loan_products.name as product, count(*) as total')
            ->groupBy('demo_loan_products.name')
            ->orderByDesc('total')
            ->pluck('total', 'product')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Disbursed amount per employee, top $limit performers.
     *
     * @return array<string, int>
     */
    public function teamPerformance(Tenant $tenant, int $limit = 8): array
    {
        return DemoApplication::query()
            ->where('demo_applications.tenant_id', $tenant->getKey())
            ->where('demo_applications.status', 'disbursed')
            ->join('demo_employees', 'demo_employees.id', '=', 'demo_applications.demo_employee_id')
            ->selectRaw('demo_employees.name as employee, sum(demo_applications.disbursed_amount) as total')
            ->groupBy('demo_employees.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'employee')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @param  Collection<string, int|float>  $rows
     * @return array{labels: list<string>, values: list<mixed>}
     */
    protected function fillMonths(Carbon $start, int $months, Collection $rows): array
    {
        $labels = [];
        $values = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $labels[] = $month->format('M Y');
            $values[] = $rows[$month->format('Y-m')] ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
