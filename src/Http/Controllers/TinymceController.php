<?php

namespace Dcat\Admin\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Dcat\Admin\Support\Helper;

class TinymceController
{
//     public function upload(Request $request)
//     {
//         $file = $request->file('file');
//         $dir = trim($request->get('dir'), '/');
//         $disk = $this->disk();

//         $newName = $this->generateNewName($file);

//         $disk->putFileAs($dir, $file, $newName);
// // dump($disk->url("{$dir}/$newName"));
//         return ['location' => $disk->url("{$dir}/$newName")];
//     }
  
    public function upload(Request $request)
    {
        $file = $request->file('file');
        $dir = trim($request->get('dir'), '/');
        $disk = $this->disk();

        // 生成新文件名（带扩展名）
        $newName = $this->generateNewName($file);
        $disk->putFileAs($dir, $file, $newName);
        // 判断上传的文件是否为图片
        if (in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'gif'])) {
            $newWebpName = pathinfo($newName, PATHINFO_FILENAME) . '.webp'; // 将扩展名改为 webp
            // 使用正斜杠统一路径，避免 Windows DIRECTORY_SEPARATOR 导致 file_exists 判断失败
            $webpPath = storage_path('app/public/' . $dir . '/' . $newWebpName);
            // 调用 convertToWebP 方法进行转换
            $converted = Helper::convertToWebP($file->getRealPath(), $webpPath);

            // 【竞态修复】必须先确认 WebP 文件真实存在且大小 > 0，再删除原始文件。
            // 原来的写法是先删原文件再判断 WebP 是否生成成功，若转换中途异常
            // （Imagick 内存不足、磁盘写入失败等），原文件已删且 WebP 未生成，图片永久丢失。
            if ($converted && file_exists($webpPath) && filesize($webpPath) > 0) {
                // WebP 确认存在且有内容后，再安全删除原始文件
                $disk->delete("{$dir}/$newName");
                // 返回 WebP 文件的访问 URL
                return ['location' => $disk->url("{$dir}/$newWebpName")];
            } else {
                // 转换失败或 WebP 文件无效，保留原文件，清理可能存在的不完整 WebP
                if (file_exists($webpPath) && filesize($webpPath) === 0) {
                    @unlink($webpPath); // 清理 0 字节的无效 WebP 文件
                }
                return ['location' => $disk->url("{$dir}/$newName")];
            }
        } else {
            // 返回原文件的访问 URL
            return ['location' => $disk->url("{$dir}/$newName")];
        }
    }

    protected function generateNewName(UploadedFile $file)
    {
        return uniqid(md5($file->getClientOriginalName())).'.'.$file->getClientOriginalExtension();
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem|FilesystemAdapter
     */
    protected function disk()
    {
        $disk = request()->get('disk') ?: config('admin.upload.disk');

        return Storage::disk($disk);
    }
}
