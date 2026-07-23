{{-- Route::has guard: a stale route cache without the module's routes must
     degrade to a missing menu item, not a layout-breaking exception. --}}
@if (Route::has('auditlog.index'))
<li class="{{ Route::is('auditlog.*') ? 'active' : '' }}"><a href="{{ route('auditlog.index') }}">{{ __('Audit') }}</a></li>
@endif
