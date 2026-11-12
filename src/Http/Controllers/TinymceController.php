<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Support\Helper;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TinymceController
{
    public function upload(Request $request)
    {
        $file = $request->file('file');

        // 只允许真实图片：后缀白名单 + 内容 MIME 检测，杜绝上传 php/html/svg 等可执行文件
        if (! Helper::isSafeImageUpload($file)) {
            return response()->json(['error' => ['message' => 'Invalid image file.']], 422);
        }

        try {
            $dir = Helper::sanitizeUploadDirectory($request->input('dir'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }

        $disk = $this->disk();

        // 生成新文件名（带扩展名）
        $newName = $this->generateNewName($file);
        $disk->putFileAs($dir, $file, $newName);

        $path = ltrim("{$dir}/{$newName}", '/');

        // 判断上传的文件是否为可转换的图片
        if (in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $newWebpName = pathinfo($newName, PATHINFO_FILENAME).'.webp'; // 将扩展名改为 webp
            $webpRelative = ltrim("{$dir}/{$newWebpName}", '/');
            // 使用正斜杠统一路径，避免 Windows DIRECTORY_SEPARATOR 导致 file_exists 判断失败
            $webpPath = storage_path('app/public/'.$webpRelative);
            // 调用 convertToWebP 方法进行转换
            $converted = Helper::convertToWebP($file->getRealPath(), $webpPath);

            // 必须先确认 WebP 文件真实存在且大小 > 0，再删除原始文件，
            // 否则转换中途异常（Imagick 内存不足、磁盘写入失败等）会导致图片永久丢失。
            if ($converted && file_exists($webpPath) && filesize($webpPath) > 0) {
                $disk->delete($path);

                return ['location' => $disk->url($webpRelative)];
            }

            // 转换失败或 WebP 文件无效，保留原文件，清理可能存在的不完整 WebP
            if (file_exists($webpPath) && filesize($webpPath) === 0) {
                @unlink($webpPath);
            }
        }

        // 返回原文件的访问 URL
        return ['location' => $disk->url($path)];
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
        return Storage::disk(static::resolveDisk(request()->input('disk')));
    }

    /**
     * 只允许使用 config/filesystems.php 中已定义的磁盘.
     *
     * @param  mixed  $disk
     * @return string
     */
    public static function resolveDisk($disk)
    {
        $default = config('admin.upload.disk');

        if (! is_string($disk) || $disk === '' || ! is_array(config("filesystems.disks.{$disk}"))) {
            return $default;
        }

        $allowed = config('admin.upload.allowed_disks');

        if (is_array($allowed) && ! in_array($disk, $allowed, true) && $disk !== $default) {
            return $default;
        }

        return $disk;
    }
}
