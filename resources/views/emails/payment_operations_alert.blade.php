<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('SkipCash payment needs attention') }}</title>
</head>
<body>
    <p>{{ __('A verified SkipCash payment needs attention in RMS.') }}</p>
    <p>{{ __('Checkout') }}: {{ $reference }}</p>
    <p>{{ __('Reason') }}: {{ str($reasonCode)->replace('_', ' ')->title() }}</p>
    <p><a href="{{ $operationsUrl }}">{{ __('Review payment') }}</a></p>
</body>
</html>
