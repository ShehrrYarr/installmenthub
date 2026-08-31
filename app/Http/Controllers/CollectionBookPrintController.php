<?php

namespace App\Http\Controllers;

use App\Support\CollectionBook;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CollectionBookPrintController extends Controller
{
    public function __invoke(Request $request): View
    {
        $month = CollectionBook::resolveMonth($request->query('month'));
        $groups = CollectionBook::groupedRows((string) $request->query('q', ''), $month);

        return view('collections.book-print', [
            'groups' => $groups,
            'shop' => Tenant::current(),
            'generatedAt' => now(),
            'monthLabel' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('F Y'),
            'isCurrentMonth' => $month === now()->format('Y-m'),
            'summary' => CollectionBook::monthSummary($month),
        ]);
    }
}
