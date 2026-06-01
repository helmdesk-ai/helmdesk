<?php

namespace App\Actions\User;

use App\Data\User\ShowTwoFactorAuthenticationSettingsPagePropsData;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * 展示个人两步验证设置页面。
 */
class ShowTwoFactorAuthenticationSettingsPageAction
{
    use AsAction;

    public function handle(TwoFactorAuthenticationRequest $request): ShowTwoFactorAuthenticationSettingsPagePropsData
    {
        $request->ensureStateIsValid();

        return new ShowTwoFactorAuthenticationSettingsPagePropsData(
            twoFactorEnabled: $request->user()->hasEnabledTwoFactorAuthentication(),
            requiresConfirmation: Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
        );
    }

    public function asController(TwoFactorAuthenticationRequest $request): Response
    {
        return Inertia::render('settings/TwoFactor', $this->handle($request)->toArray());
    }
}
