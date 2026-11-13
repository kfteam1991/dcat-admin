<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Exception\AdminException;
use Dcat\Admin\Form\Field\Embeds;
use Dcat\Admin\Form\Field\File;
use Dcat\Admin\Form\Field\HasMany;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Support\Helper;
use Dcat\Admin\Traits\HasUploadedFile;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;

class HandleFormController
{
    use HasUploadedFile;

    public function handle(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        if ($errors = $form->validate($request)) {
            return $form->validationErrorsResponse($errors);
        }

        $input = $form->sanitize($request->all());

        return $this->sendResponse($form->handle($input));
    }

    public function uploadFile(Request $request)
    {
        $form = $this->resolveForm($request);

        $form->form();

        return $this->getField($request, $form)->upload($this->file());
    }

    /**
     * @param  Request  $request
     * @param $form
     * @return File
     */
    protected function getField(Request $request, $form)
    {
        $column = $this->uploader()->upload_column ?: $request->get('_column');

        if (! $relation = $request->get('_relation')) {
            return $form->field($column);
        }

        $relation = is_array($relation) ? current($relation) : $relation;

        $relationField = $form->field($relation);

        if (! $relationField) {
            return;
        }

        if ($relationField instanceof HasMany) {
            return $relationField->buildNestedForm()->field($column);
        }
        if ($relationField instanceof Embeds) {
            return $relationField->field($column);
        }
    }

    public function destroyFile(Request $request)
    {
        $form = $this->resolveForm($request);

        $form->form();

        $field = $this->getField($request, $form);

        if (! $field instanceof File) {
            throw new AdminException('Invalid upload field.');
        }

        $paths = array_filter((array) $request->input('key'), 'is_string');

        // 只允许删除该字段上传目录下的文件，防止任意文件删除
        foreach ($paths as $path) {
            if (! $field->isDeletablePath($path)) {
                return $this->responseErrorMessage('Invalid file path.');
            }
        }

        $field->deleteFile($paths);

        return $this->responseDeleted();
    }

    /**
     * @param  Request  $request
     * @return Form
     *
     * @throws AdminException
     */
    protected function resolveForm(Request $request)
    {
        if (! $request->has(Form::REQUEST_NAME)) {
            throw new AdminException('Invalid form request.');
        }

        $formClass = $request->input(Form::REQUEST_NAME);

        // 先校验类型再实例化，防止通过请求参数实例化任意类
        if (! is_string($formClass) || ! class_exists($formClass) || ! is_subclass_of($formClass, Form::class)) {
            throw new AdminException('Form does not exist.');
        }

        /** @var Form $form */
        $form = app($formClass);

        if (! method_exists($form, 'handle')) {
            throw new AdminException("Form method {$formClass}::handle() does not exist.");
        }

        return $form;
    }

    protected function sendResponse($response)
    {
        if ($response instanceof JsonResponse) {
            return $response->send();
        }

        return $response;
    }
}
