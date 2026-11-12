<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Support\Helper;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class EditorMDController
{
    public function upload(Request $request)
    {
        $file = $request->file('editormd-image-file');

        // 只允许真实图片，杜绝上传 php/html/svg 等可执行文件
        if (! Helper::isSafeImageUpload($file)) {
            return ['success' => 0, 'message' => 'Invalid image file.'];
        }

        try {
            $dir = Helper::sanitizeUploadDirectory($request->input('dir'));
        } catch (\InvalidArgumentException $e) {
            return ['success' => 0, 'message' => $e->getMessage()];
        }

        $disk = $this->disk();

        $newName = $this->generateNewName($file);

        $disk->putFileAs($dir, $file, $newName);

        return ['success' => 1, 'url' => $disk->url(ltrim("{$dir}/{$newName}", '/'))];
    }

    protected function generateNewName(UploadedFile $file)
    {
        return uniqid(md5($file->getClientOriginalName())).'.'.strtolower($file->getClientOriginalExtension());
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem|FilesystemAdapter
     */
    protected function disk()
    {
        return Storage::disk(TinymceController::resolveDisk(request()->input('disk')));
    }
}
