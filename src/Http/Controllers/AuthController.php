<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Http\Repositories\Administrator;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Support\Helper;
use Dcat\Admin\Traits\HasFormResponse;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use HasFormResponse;

    /**
     * @var string
     */
    protected $view = 'admin::pages.login';

    /**
     * @var string
     */
    protected $redirectTo;

    /**
     * Show the login page.
     *
     * @return Content|\Illuminate\Http\RedirectResponse
     */
    public function getLogin(Content $content)
    {
        if ($this->guard()->check()) {
            return redirect($this->getRedirectPath());
        }

        return $content->full()->body(view($this->view));
    }

    /**
     * Handle a login request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function postLogin(Request $request)
    {
        $credentials = $request->only([$this->username(), 'password']);
        $remember = (bool) $request->input('remember', false);

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($credentials, [
            $this->username()   => 'required|string|max:255',
            'password'          => 'required|string|max:1024',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorsResponse($validator);
        }

        // 登录失败次数限制，防止暴力破解
        $throttleKey = $this->throttleKey($request);
        [$maxAttempts, $decaySeconds] = $this->loginThrottle();

        if ($maxAttempts > 0 && $this->limiter()->tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = $this->limiter()->availableIn($throttleKey);

            return $this->validationErrorsResponse([
                $this->username() => Lang::has('auth.throttle')
                    ? trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])
                    : "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if ($this->guard()->attempt($credentials, $remember)) {
            $this->limiter()->clear($throttleKey);

            return $this->sendLoginResponse($request);
        }

        if ($maxAttempts > 0) {
            $this->limiter()->hit($throttleKey, $decaySeconds);
        }

        return $this->validationErrorsResponse([
            $this->username() => $this->getFailedLoginMessage(),
        ]);
    }

    /**
     * User logout.
     *
     * @return Redirect|string
     */
    public function getLogout(Request $request)
    {
        // 防止 CSRF 强制登出：必须携带有效的 _token，否则展示一个需要手动确认的 POST 表单
        if (! $this->hasValidLogoutToken($request)) {
            return response($this->logoutConfirmationHtml());
        }

        $this->guard()->logout();

        $request->session()->invalidate();

        $path = admin_url('auth/login');
        if ($request->pjax()) {
            return '<script>location.href = '.Helper::jsString($path).';</script>';
        }

        return redirect($path);
    }

    /**
     * User setting page.
     *
     * @param  Content  $content
     * @return Content
     */
    public function getSetting(Content $content)
    {
        $form = $this->settingForm();
        $form->tools(
            function (Form\Tools $tools) {
                $tools->disableList();
            }
        );

        return $content
            ->title(trans('admin.user_setting'))
            ->body($form->edit(Admin::user()->getKey()));
    }

    /**
     * Update user setting.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putSetting()
    {
        $form = $this->settingForm();

        if (! $this->validateCredentialsWhenUpdatingPassword()) {
            $form->responseValidationMessages('old_password', trans('admin.old_password_error'));
        }

        return $form->update(Admin::user()->getKey());
    }

    protected function validateCredentialsWhenUpdatingPassword()
    {
        $user = Admin::user();

        $oldPassword = \request('old_password');
        $newPassword = \request('password');

        if (
            (! $newPassword)
            || ($newPassword === $user->getAuthPassword())
        ) {
            return true;
        }

        if (! $oldPassword) {
            return false;
        }

        return $this->guard()
            ->getProvider()
            ->validateCredentials($user, ['password' => $oldPassword]);
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        return new Form(new Administrator(), function (Form $form) {
            $form->action(admin_url('auth/setting'));

            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();

            $form->tools(function (Form\Tools $tools) {
                $tools->disableView();
                $tools->disableDelete();
            });

            $form->display('username', trans('admin.username'));
            $form->text('name', trans('admin.name'))->required();
            $form->image('avatar', trans('admin.avatar'))->autoUpload();

            $form->password('old_password', trans('admin.old_password'));

            $form->password('password', trans('admin.password'))
                ->minLength(5)
                ->maxLength(20)
                ->customFormat(function ($v) {
                    if ($v == $this->password) {
                        return;
                    }

                    return $v;
                });
            $form->password('password_confirmation', trans('admin.password_confirmation'))->same('password');

            $form->ignore(['password_confirmation', 'old_password']);

            $form->saving(function (Form $form) {
                if ($form->password && $form->model()->password != $form->password) {
                    $form->password = bcrypt($form->password);
                }

                if (! $form->password) {
                    $form->deleteInput('password');
                }
            });

            $form->saved(function (Form $form) {
                return $form
                    ->response()
                    ->success(trans('admin.update_succeeded'))
                    ->redirect('auth/setting');
            });
        });
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        return Lang::has('admin.auth_failed')
            ? trans('admin.auth_failed')
            : 'These credentials do not match our records.';
    }

    /**
     * Get the post login redirect path.
     *
     * @return string
     */
    protected function getRedirectPath()
    {
        return $this->redirectTo ?: admin_url('/');
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function sendLoginResponse(Request $request)
    {
        $request->session()->regenerate();

        $path = $this->getRedirectPath();

        return $this->response()
            ->success(trans('admin.login_successful'))
            ->locationToIntended($path)
            ->locationIf(Admin::app()->getEnabledApps(), $path)
            ->send();
    }

    /**
     * @param  Request  $request
     * @return bool
     */
    protected function hasValidLogoutToken(Request $request)
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');
        $sessionToken = $request->hasSession() ? $request->session()->token() : null;

        return is_string($token) && is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }

    /**
     * @return string
     */
    protected function logoutConfirmationHtml()
    {
        $action = e(admin_url('auth/logout'));
        $token = e(csrf_token());
        $label = e(trans('admin.logout'));
        $back = e(admin_url('/'));
        $cancel = e(trans('admin.cancel'));

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$label}</title></head>
<body style="font-family:sans-serif;text-align:center;padding-top:15vh">
<form method="post" action="{$action}">
    <input type="hidden" name="_token" value="{$token}">
    <button type="submit" style="padding:10px 28px;font-size:16px;cursor:pointer">{$label}</button>
    <a href="{$back}" style="margin-left:16px">{$cancel}</a>
</form>
</body>
</html>
HTML;
    }

    /**
     * @return \Illuminate\Cache\RateLimiter
     */
    protected function limiter()
    {
        return app(RateLimiter::class);
    }

    /**
     * 登录限流配置：admin.auth.throttle = [最大失败次数, 锁定秒数]，设为 false 关闭.
     *
     * @return array
     */
    protected function loginThrottle()
    {
        $config = config('admin.auth.throttle', [5, 60]);

        if (! $config) {
            return [0, 0];
        }

        return [(int) ($config[0] ?? 5), (int) ($config[1] ?? 60)];
    }

    /**
     * @param  Request  $request
     * @return string
     */
    protected function throttleKey(Request $request)
    {
        return 'dcat-admin-login|'.Str::lower((string) $request->input($this->username())).'|'.$request->ip();
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username()
    {
        return 'username';
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard|GuardHelpers
     */
    protected function guard()
    {
        return Admin::guard();
    }
}
