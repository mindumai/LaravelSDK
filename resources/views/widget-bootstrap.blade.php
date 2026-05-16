@if ($enabled)
    <script>
        window.__MINDUM_WIDGET__ = @json($config);
    </script>
    <script src="{{ $config['bundleUrl'] }}" defer></script>
@endif
