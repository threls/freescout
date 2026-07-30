{{--
    Rendered into the thread's own options dropdown via the thread.menu
    action. data-confirm carries its text from here rather than from
    Vars/LangMessages so the string stays translatable without patching
    core's resources/views/js/vars.blade.php — the same approach the
    Workflows module uses for its own delete confirmation.
--}}
<li>
    <a href="#" class="unar-use-as-reply" role="button" title="{{ __('Copy this note into the reply editor') }}" data-confirm="{{ __('Replace what you have already written in the reply?') }}">{{ __('Use as Reply') }}</a>
</li>
