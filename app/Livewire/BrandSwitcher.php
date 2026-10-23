<?php

namespace App\Livewire;

use App\Support\Brand;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Umschalter oben im CRM (Render-Hook im AdminPanelProvider): ADK, JB IT-Design oder alle Marken.
 * Gilt für Heute, Anrufliste, Vorgänge, Kalender und Auswertung. Ein Konto mit nur einer Marke
 * sieht stattdessen den Namen dieser Marke.
 */
class BrandSwitcher extends Component
{
    public string $brand = '';

    /** Seite, auf die nach dem Umschalten neu geladen wird. */
    public string $returnTo = '';

    public function mount(): void
    {
        $this->brand = Brand::current() ?? '';
        $this->returnTo = url()->current();
    }

    public function updatedBrand(string $value): void
    {
        Brand::select($value !== '' ? $value : null);

        $this->redirect($this->returnTo ?: url('/'));
    }

    public function render(): View
    {
        return view('livewire.brand-switcher', [
            'options' => Brand::options(auth()->user()),
        ]);
    }
}
