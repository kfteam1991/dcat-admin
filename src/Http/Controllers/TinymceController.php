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
        $originalName = $this->generateNewName($file); 
        $newName = pathinfo($originalName, PATHINFO_FILENAME) . '.webp'; // 将扩展名改为 webp
        // 判断上传的文件是否为图片
        if (in_array($file->getClientOriginalExtension(), ['jpg', 'jpeg', 'png', 'gif'])) {
            // 定义 WebP 输出路径
            $webpPath = storage_path("app" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "{$dir}" . DIRECTORY_SEPARATOR . "{$newName}");
            // 调用 convertToWebP 方法进行转换
            Helper::convertToWebP($file->getRealPath(), $webpPath);

            // 返回 WebP 文件的访问 URL
            return ['location' => $disk->url("{$dir}/$newName")];
        } else {
            // 如果不是图片，直接存储原文件
            $disk->putFileAs($dir, $file, $newName);
            
            // 返回原文件的访问 URL
            return ['location' => $disk->url("{$dir}/$newName")];
        }
    }

    /**
     * 删除图片
     *
     * @return void
     */
    public function delete(Request $request)
    {
        $imagePath = urldecode($request->input('image'));//路径解码
        // 提取相对路径
        $imagePath = str_replace(url('storage/'), '', $imagePath); 
        // 删除图片逻辑
        if (Storage::disk('public')->exists($imagePath)) {
            $result = Storage::disk('public')->delete($imagePath);
            return response()->json(['success' =>  $result, 'message' => 'Image deleted successfully.']);
        }
    
        return response()->json(['success' => false, 'message' => 'Image not found.' . $imagePath], 404);
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
