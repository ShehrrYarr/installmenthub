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
        $groups = CollectionBook::groupedRows((string) $request->query('q', ''));

        return view('collections.book-print', [
            'groups' => $groups,
            'shop' => Tenant::current(),
            'generatedAt' => now(),
        ]);
    }
}
