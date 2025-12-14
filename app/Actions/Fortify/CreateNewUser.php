<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique(User::class)],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $name = isset($input['name']) && is_string($input['name']) && trim($input['name']) !== ''
            ? trim($input['name'])
            : Str::headline($input['username']);

        // #region agent log
        try {
            file_put_contents(
                base_path('.cursor/debug.log'),
                json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'H_USER_NAME',
                    'location' => 'app/Actions/Fortify/CreateNewUser.php:create',
                    'message' => 'CreateNewUser computed name',
                    'data' => [
                        'has_name_input' => isset($input['name']) && is_string($input['name']) && trim($input['name']) !== '',
                        'name_source' => (isset($input['name']) && is_string($input['name']) && trim($input['name']) !== '') ? 'input' : 'username',
                        'will_set_name' => $name !== '',
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // ignore
        }
        // #endregion

        return User::create([
            'name' => $name,
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
            'status' => 'active',
        ]);
    }
}
