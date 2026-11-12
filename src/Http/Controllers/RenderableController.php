<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Support\Helper;
use Illuminate\Http\Request;

class RenderableController
{
    public function handle(Request $request)
    {
        $this->initTranslation($request);

        $renderable = $this->newRenderable($request);

        if (method_exists($renderable, 'passesAuthorization') && ! $renderable->passesAuthorization()) {
            return $renderable->failedAuthorization();
        }

        $this->addScript();

        $this->forgetDefaultAssets();

        return $this->render($renderable);
    }

    protected function render(LazyRenderable $renderable)
    {
        $asset = Admin::asset();

        return Helper::render($renderable->render())
            .Admin::html()
            .$asset->jsToHtml()
            .$asset->cssToHtml()
            .$asset->scriptToHtml()
            .$asset->styleToHtml();
    }

    protected function initTranslation(Request $request)
    {
        if (($path = $request->input('_trans_')) && is_string($path)) {
            Admin::translation($path);
        }
    }

    protected function newRenderable(Request $request): LazyRenderable
    {
        $class = $request->input('renderable');

        $class = is_string($class) ? str_replace('_', '\\', $class) : '';

        // 先校验类型再实例化，防止通过请求参数实例化任意类
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, LazyRenderable::class)) {
            abort(404, 'Renderable does not exist.');
        }

        $renderable = new $class();

        $renderable->payload($request->all());

        if (method_exists($renderable, 'requireAssets')) {
            $renderable->requireAssets();
        }

        return $renderable;
    }

    protected function addScript()
    {
        // 等待JS脚本加载完成
        Admin::script('Dcat.wait()', true);
    }

    protected function forgetDefaultAssets()
    {
        Admin::baseJs([], false);
        Admin::baseCss([], false);
        Admin::fonts([]);
    }
}
