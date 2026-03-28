<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 Forbidden</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #f6f3ee;
            color: #1f2937;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 24px;
        }

        .card {
            width: min(100%, 560px);
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }

        .eyebrow {
            margin: 0 0 8px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #8b5e11;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 30px;
            line-height: 1.1;
            color: #111827;
        }

        p {
            margin: 0;
            line-height: 1.6;
            color: #4b5563;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        .button,
        .button-secondary {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .button {
            background: #1f3b86;
            color: #fff;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }
    </style>
</head>
<body>
    @php($user = auth()->user())
    @php($isCustomerPortalUser = $user && method_exists($user, 'hasRole') && $user->hasRole('customer'))

    <main class="card">
        <p class="eyebrow">Access Restricted</p>
        <h1>403 Forbidden</h1>
        <p>{{ $exception->getMessage() ?: 'You are not allowed to access this page.' }}</p>

        <div class="actions">
            @if ($isCustomerPortalUser)
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="button">Logout</button>
                </form>
                <a href="/" class="button-secondary">Return Home</a>
            @else
                <a href="{{ route('login') }}" class="button-secondary">Back to Login</a>
            @endif
        </div>
    </main>
</body>
</html>
