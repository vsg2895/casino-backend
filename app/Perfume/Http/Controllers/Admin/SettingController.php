<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\UpdateSettingRequest;
use App\Perfume\Http\Resources\SettingResource;
use App\Perfume\Models\Setting;

class SettingController extends Controller
{
    public function show(): SettingResource
    {
        return new SettingResource(Setting::current());
    }

    public function update(UpdateSettingRequest $request): SettingResource
    {
        $setting = Setting::current();
        $setting->update($request->validated());

        return new SettingResource($setting->fresh());
    }
}
