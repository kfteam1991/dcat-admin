<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Traits\InteractsWithApi;
use Exception;
use Illuminate\Http\Request;

class ValueController
{
    /**
     * @param  Request  $request
     * @return mixed
     */
    public function handle(Request $request)
    {
        $instance = $this->resolve($request);

        if (! $instance->passesAuthorization()) {
            return $instance->failedAuthorization();
        }

        $response = $instance->handle($request);

        if ($response) {
            return $response;
        }

        if (method_exists($instance, 'valueResult')) {
            return $instance->valueResult();
        }
    }

    /**
     * @param  Request  $request
     * @return \Dcat\Admin\Traits\InteractsWithApi
     *
     * @throws Exception
     */
    protected function resolve(Request $request)
    {
        $key = $request->input('_key');

        if (! $key || ! is_string($key)) {
            throw new Exception('Invalid request.');
        }

        // 先校验类型再实例化，防止通过请求参数实例化任意类
        if (! class_exists($key) || ! in_array(InteractsWithApi::class, class_uses_recursive($key), true)) {
            throw new Exception('Class does not exist.');
        }

        $instance = app($key);

        if (! method_exists($instance, 'handle')) {
            throw new Exception("The method '{$key}::handle()' does not exist.");
        }

        return $instance;
    }
}
