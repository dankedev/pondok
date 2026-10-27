<?php

namespace App\Livewire\Admin;

use App\Filament\GlobalSearch\NavigationAwareGlobalSearchProvider;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Palet perintah panel admin (Ctrl+K). Isinya dari provider pencarian yang sudah
 * disaring permission dan saklar modul; komponen ini hanya menampilkannya.
 */
class CommandPalette extends Component
{
    public string $search = '';

    public function render(): View
    {
        $categories = app(NavigationAwareGlobalSearchProvider::class)
            ->getResults($this->search)
            ->getCategories()
            ->map(fn ($results) => collect($results))
            ->filter(fn ($results): bool => $results->isNotEmpty());

        return view('filament.command-palette', ['categories' => $categories]);
    }
}
