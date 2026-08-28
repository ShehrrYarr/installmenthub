{{-- Livewire's full-page-component wrapper (config('livewire.layout')). Every
     Volt page in this app declares its own layout explicitly via the
     #[Layout('layouts.tenant')] / #[Layout('layouts.super-admin')] /
     #[Layout('layouts.marketing')] attribute, so this default is a
     deliberate passthrough — it must not add any markup of its own. --}}
{{ $slot }}
