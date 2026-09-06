<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payment consistency issue needs attention') }}</title>
</head>
<body>
    <p>{{ __('A payment or membership consistency check found an issue that needs administrator review.') }}</p>
    <p>{{ __('Rule') }}: {{ str($ruleCode)->replace('_', ' ')->title() }}</p>
    <p>{{ __('Subject') }}: {{ str($subjectType)->replace('_', ' ')->title() }} #{{ $subjectId }}</p>
    <p>{{ __('Issues') }}: {{ collect($issueCodes)->map(fn ($code) => str($code)->replace('_', ' ')->title())->implode(', ') }}</p>
    <p>{{ __('Episode') }}: {{ $episodeUuid }}</p>
    <p><a href="{{ $reviewUrl }}">{{ __('Review consistency finding') }}</a></p>
</body>
</html>
