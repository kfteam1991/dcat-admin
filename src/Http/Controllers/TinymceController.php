<?php

namespace Dcat\Admin\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Intervention\Image\ImageManagerStatic as Image;
use Intervention\Image\ImageManager;
class TinymceController
{
    // public function upload(Request $request)
    // {
    //     $file = $request->file('file');
    //     $dir = trim($request->get('dir'), '/');
    //     $disk = $this->disk();

    //     $newName = $this->generateNewName($file);

    //     $disk->putFileAs($dir, $file, $newName);

    //     return ['location' => $disk->url("{$dir}/$newName")];
    // }

    public function upload(Request $request)
    {
        $file = $request->file('file');
        $dir = trim($request->get('dir'), '/');
        $disk = $this->disk();

        // 生成新文件名（带扩展名）
        $originalName = $this->generateNewName($file); 
        $newName = pathinfo($originalName, PATHINFO_FILENAME) . '.webp'; // 将扩展名改为 webp

        // 保存原始文件到临时路径
        $tempPath = sys_get_temp_dir() . '/' . $originalName;
        $file->move(sys_get_temp_dir(), $originalName);

        // 将图片压缩为 WebP 格式
        $image = Image::make($tempPath);
        $webpPath = sys_get_temp_dir() . '/' . $newName;
        $image->encode('webp', 80)->save($webpPath); // 压缩质量设置为 80

        // 将 WebP 图片上传到目标存储
        $disk->putFileAs($dir, new \Illuminate\Http\File($webpPath), $newName);

        // 删除临时文件
        @unlink($tempPath);
        @unlink($webpPath);

        // 返回 WebP 图片的 URL
        return ['location' => $disk->url("{$dir}/$newName")];
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
       // 使用 DIRECTORY_SEPARATOR 确保路径兼容性
        $imagePath = 'public' . DIRECTORY_SEPARATOR . $imagePath;
        // 删除图片逻辑
        if (Storage::exists($imagePath)) {
            $result = Storage::delete($imagePath);
            return response()->json(['success' =>  $result, 'message' => 'Image deleted successfully.']);
        }
    
        return response()->json(['success' => false, 'message' => 'Image not found.'], 404);
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
