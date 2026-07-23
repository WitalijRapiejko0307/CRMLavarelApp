<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Income;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /finances
     * Finance dashboard: expenses + income for a given month.
     */
    public function index(Request $request): Response
    {
        $month = $request->input('month', now()->format('Y-m'));

        [$year, $mon] = explode('-', $month);

        $expenses = Expense::whereYear('date', $year)
            ->whereMonth('date', $mon)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $incomeList = Income::whereYear('date', $year)
            ->whereMonth('date', $mon)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $totalExpenses = $expenses->sum('amount');
        $totalIncome   = $incomeList->sum('amount');

        // By-category summary for expenses
        $byCategory = $expenses
            ->groupBy('category')
            ->map(fn ($items) => $items->sum('amount'))
            ->sortByDesc(fn ($v) => $v)
            ->all();

        return Inertia::render('Finance/Index', [
            'expenses'       => $expenses,
            'incomeList'     => $incomeList,
            'totalExpenses'  => $totalExpenses,
            'totalIncome'    => $totalIncome,
            'profit'         => $totalIncome - $totalExpenses,
            'byCategory'     => $byCategory,
            'categories'     => Expense::CATEGORIES,
            'month'          => $month,
        ]);
    }

    // ─── Expenses ─────────────────────────────────────────────────────────────

    /**
     * POST /finances/expenses
     */
    public function storeExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'category'    => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'date'        => ['required', 'date'],
        ]);

        $expense = Expense::create(array_merge($data, [
            'tenant_id' => Auth::user()->tenant_id,
        ]));

        return response()->json(['success' => true, 'expense' => $expense]);
    }

    /**
     * DELETE /finances/expenses/{expense}
     */
    public function destroyExpense(Expense $expense): JsonResponse
    {
        abort_if($expense->tenant_id !== Auth::user()->tenant_id, 403);

        $expense->delete();

        return response()->json(['success' => true]);
    }

    // ─── Income ───────────────────────────────────────────────────────────────

    /**
     * POST /finances/income
     */
    public function storeIncome(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
            'source'      => ['nullable', 'string', 'max:100'],
            'date'        => ['required', 'date'],
        ]);

        $income = Income::create(array_merge($data, [
            'tenant_id' => Auth::user()->tenant_id,
        ]));

        return response()->json(['success' => true, 'income' => $income]);
    }

    /**
     * DELETE /finances/income/{income}
     */
    public function destroyIncome(Income $income): JsonResponse
    {
        abort_if($income->tenant_id !== Auth::user()->tenant_id, 403);

        $income->delete();

        return response()->json(['success' => true]);
    }
}
