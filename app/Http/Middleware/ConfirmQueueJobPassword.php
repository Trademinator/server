<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmQueueJobPassword extends RequirePassword
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): Response
    {
        $passwordTimeoutSeconds ??= config('auth.password_timeout') ?: 10800;
        if (! $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
        }
        $selection = $request->validate([
            'connection' => ['required', 'string', 'max:160'],
            'queue' => ['required', 'string', 'max:160'],
        ]);

        $errors = new MessageBag;
        if ($request->has('password')) {
            $validator = Validator::make($request->only('password'), [
                'password' => ['bail', 'required', 'string', 'current_password:web'],
            ]);
            if ($validator->passes()) {
                $request->session()->passwordConfirmed();

                return $next($request);
            }
            $errors = $validator->errors();
        }
        View::share('errors', (new ViewErrorBag)->put('default', $errors));

        return response()->view('auth.confirm-password', [
            'formAction' => route('owner.queue-jobs.destroy', $request->route('job')),
            'formFields' => ['_method' => 'DELETE',
                'connection' => $selection['connection'],
                'queue' => $selection['queue']],
            'confirmLabel' => __('Confirm and cancel pending job'),
        ], $errors->isEmpty() ? 200 : 422);
    }
}
